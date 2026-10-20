<?php
declare(strict_types=1);

namespace DPS;

/**
 * The switch itself: the timer, the escalation, and everything that changes
 * the switch's state.
 *
 * How it keeps time
 * -----------------
 * Nothing here depends on running at a particular moment. tick() is called
 * every 15 minutes or so (by cron, or by the secret URL), looks at the stored
 * timestamps, and does whatever has fallen due. If the server is off for a
 * day, the next tick simply picks up where things stood: late, never wrong.
 *
 * How it escalates
 * ----------------
 * One "cycle" runs per weekly check-in email:
 *
 *   checkin  the owner has been emailed and reminded       (72 hours)
 *   verify   the next of kin has been asked                (72 hours)
 *   fired    every confirmed contact has their message
 *
 * A step only counts once its email has been accepted by the mail server,
 * and steps are never skipped or bunched together. So a broken mail server
 * or a stopped cron job delays the escalation; it cannot cause one.
 *
 * With Telegram set up as well, the owner hears about each step on both.
 * The next of kin is not asked until the owner's reminders have been
 * delivered on both, so one message lost to a spam folder or a dead
 * channel cannot start the escalation on its own.
 *
 * The owner can check in at any stage. Anyone already contacted is then
 * told it was a false alarm.
 */
final class Engine
{
    private Contacts $contacts;
    /** @var callable(Store, string): array */
    private $prober;

    public function __construct(private Store $store, private Outbox $outbox, ?callable $prober = null)
    {
        $this->contacts = new Contacts($store);
        $this->prober = $prober ?? [Probe::class, 'run'];
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    public function config(): array
    {
        return $this->store->read('config');
    }

    public function state(): array
    {
        return $this->store->read('state') + [
            'armed' => false,
            'armed_at' => null,
            'paused_until' => null,
            'last_checkin_at' => null,
            'last_checkin_via' => null,
            'next_due_at' => null,
            'cycle' => null,
            'first_tick_at' => null,
            'last_tick_at' => null,
            'last_tick_source' => null,
            'probe' => null,
            'review_at' => null,
            'review_asked_at' => null,
            'after_fire_at' => null,
            'all_clear_queue' => [],
        ];
    }

    public function contacts(): Contacts
    {
        return $this->contacts;
    }

    public function installed(): bool
    {
        return !empty($this->config()['installed_at']);
    }

    // ------------------------------------------------------------------
    // The scheduler
    // ------------------------------------------------------------------

    /**
     * Do whatever is due. Safe to call as often as you like.
     *
     * @param string $source 'cron' or 'url'
     * @return string[] a plain description of what happened, for logs
     */
    public function tick(string $source = 'cron'): array
    {
        if (!$this->store->exists('config')) {
            return ['Not installed yet.'];
        }
        return $this->store->locked(function () use ($source): array {
            $now = Clock::now();
            $did = [];

            $state = $this->state();
            $state['first_tick_at'] ??= $now;
            $state['last_tick_at'] = $now;
            $state['last_tick_source'] = $source;
            $this->saveState($state);

            $config = $this->config();
            if (empty($config['installed_at'])) {
                return ['Install not finished. The run was recorded.'];
            }

            $this->housekeeping($state, $config, $now, $did);

            if ($state['paused_until'] !== null && $now >= $state['paused_until']) {
                $state['paused_until'] = null;
                $state['next_due_at'] = $this->nextSlot($config, $now);
                $this->saveState($state);
                $this->store->log('pause.ended', 'The pause ended. The switch is active again.');
                $this->outbox->toOwner($this->ownerAddresses($config), Messages::ownerPauseEnded($config, $state['next_due_at']));
                $did[] = 'Pause ended.';
            }

            if ($state['armed'] && $state['paused_until'] === null) {
                $this->advance($state, $config, $now, $did);
            }

            $this->saveState($state);
            return $did ?: ['Nothing due.'];
        });
    }

    /** Move the current cycle forward by at most one step. */
    private function advance(array &$state, array $config, int $now, array &$did): void
    {
        if ($state['cycle'] === null) {
            $state['next_due_at'] ??= $this->nextSlot($config, $now);
            if ($now < $state['next_due_at']) {
                return;
            }
            $this->startCycle($state, $config, $now, $did);
            return;
        }

        $plan = self::plan($state['cycle'], null);

        switch ($state['cycle']['stage']) {
            case 'checkin':
                foreach (array_keys(Schedule::OWNER_REMINDERS) as $i) {
                    $n = $i + 1;
                    $step = $plan['reminder' . $n];
                    if (!$step['done']) {
                        if ($now >= $step['at']) {
                            $this->remindOwner($state, $config, $n, $did);
                        }
                        return;
                    }
                }
                if ($now >= $plan['verify']['at'] && !$this->holdForChannels($state, $config, $now, $plan['verify']['at'], $did)) {
                    $this->askNextOfKin($state, $config, $now, $did);
                }
                return;

            case 'verify':
                if (!$plan['nok_reminder']['done']) {
                    if ($now >= $plan['nok_reminder']['at']) {
                        $this->remindNextOfKin($state, $config, $now, $did);
                    }
                    return;
                }
                if ($now >= $plan['fire']['at']) {
                    $this->fire($state, $config, 'timeout', $did);
                }
                return;

            case 'fired':
                $this->deliverFired($state, $config, $did);
                return;
        }
    }

    private function startCycle(array &$state, array $config, int $now, array &$did): void
    {
        [$token, $record] = Tokens::issue(Schedule::CHECKIN_TOKEN_TTL);
        $email = Messages::checkin(
            $config,
            $this->url($config, 'checkin.php', ['t' => $token]),
            0,
            $now + Schedule::OWNER_WINDOW,
            $this->contacts->nok(),
            array_column($this->warnings(), 'text')
        );
        // The clock for this cycle starts when the email is accepted, not when it was due.
        $reached = $this->outbox->toOwner([(string) $config['owner_email']], $email);
        if ($reached === []) {
            $did[] = 'Could not send the check-in email. Will try again on the next run.';
            return;
        }
        $state['cycle'] = [
            'id' => gmdate('Ymd-His', $now),
            'due_at' => $state['next_due_at'],
            'started_at' => $now,
            'stage' => 'checkin',
            'tokens' => [$record],
            'reminders' => [],
            'owner_reached' => array_fill_keys($reached, $now),
            'chased_at' => null,
            'verify' => null,
            'fired_at' => null,
            'fired_reason' => null,
            'notified' => [],
            'owner_told_fired_at' => null,
        ];
        $this->saveState($state);
        $this->store->log('cycle.started', 'Weekly check-in email sent.');
        $did[] = 'Sent the weekly check-in email.';
    }

    private function remindOwner(array &$state, array $config, int $number, array &$did): void
    {
        [$token, $record] = Tokens::issue(Schedule::CHECKIN_TOKEN_TTL);
        $plan = self::plan($state['cycle'], null);
        $email = Messages::checkin(
            $config,
            $this->url($config, 'checkin.php', ['t' => $token]),
            $number,
            $plan['verify']['at'],
            $this->contacts->nok(),
            array_column($this->warnings(), 'text')
        );
        $reached = $this->outbox->toOwner($this->ownerAddresses($config), $email);
        if ($reached === []) {
            $did[] = "Could not send reminder {$number}. Will try again on the next run.";
            return;
        }
        $state['cycle']['tokens'][] = $record;
        $this->markReached($state, $reached);
        $state['cycle']['reminders'][] = Clock::now();
        $this->saveState($state);
        $this->store->log('cycle.reminder', "Reminder {$number} sent to the owner.");
        $did[] = "Sent reminder {$number} to the owner.";
    }

    /** @param string[] $channels */
    private function markReached(array &$state, array $channels): void
    {
        $state['cycle']['owner_reached'] = (array) ($state['cycle']['owner_reached'] ?? []) + array_fill_keys($channels, Clock::now());
    }

    /**
     * The owner's channels that no reminder has reached this cycle.
     *
     * @return string[]
     */
    private function unreachedChannels(array $cycle, array $config): array
    {
        // Cycles started before channels were tracked were started by email.
        $reached = (array) ($cycle['owner_reached'] ?? ['email' => $cycle['started_at']]);
        return array_values(array_diff(Outbox::ownerChannels($config), array_keys($reached)));
    }

    /**
     * Keep the next of kin waiting while a channel has not reached the owner,
     * chasing the owner on it in the meantime. Gives up after CHANNEL_HOLD.
     *
     * @return bool true while holding
     */
    private function holdForChannels(array &$state, array $config, int $now, int $verifyAt, array &$did): bool
    {
        $missing = $this->unreachedChannels($state['cycle'], $config);
        $chased = $state['cycle']['chased_at'] ?? null;
        $holdUntil = $verifyAt + Schedule::CHANNEL_HOLD;
        if ($missing === []) {
            // A chase that got through gives the owner time to see it before the next of kin is asked.
            return $chased !== null && $now < min($chased + Schedule::CHASE_EVERY, $holdUntil);
        }
        if ($now >= $holdUntil) {
            if (empty($state['cycle']['hold_ended'])) {
                $state['cycle']['hold_ended'] = true;
                $this->store->log('cycle.hold_ended', 'Your reminders never reached you by ' . self::channelNames($missing)
                    . ' in ' . duration(Schedule::CHANNEL_HOLD) . '. The next of kin is being asked anyway.');
            }
            return false;
        }

        if ($chased === null || $now >= $chased + Schedule::CHASE_EVERY) {
            [$token, $record] = Tokens::issue(Schedule::CHECKIN_TOKEN_TTL);
            $email = Messages::ownerChase(
                $config,
                $this->url($config, 'checkin.php', ['t' => $token]),
                array_map([self::class, 'channelName'], array_keys((array) ($state['cycle']['owner_reached'] ?? ['email' => 0]))),
                $holdUntil
            );
            $reached = $this->outbox->toOwner($this->ownerAddresses($config), $email, $missing);
            $state['cycle']['tokens'][] = $record;
            $state['cycle']['chased_at'] = $now;
            $this->markReached($state, $reached);
            $this->saveState($state);
            if ($chased === null) {
                $this->store->log('cycle.hold', 'No check-in after 72 hours, but your reminders have not reached you by '
                    . self::channelNames($missing) . '. The next of kin will not be asked until they do.');
            }
            if ($reached !== []) {
                $did[] = 'Reached the owner by ' . self::channelNames($reached) . '.';
                return true;
            }
        }
        $did[] = 'Holding: the owner has not been reached by ' . self::channelNames($missing) . '.';
        return true;
    }

    private static function channelName(string $channel): string
    {
        return $channel === 'telegram' ? 'Telegram' : 'email';
    }

    /** @param string[] $channels */
    private static function channelNames(array $channels): string
    {
        return implode(' or ', array_map([self::class, 'channelName'], $channels));
    }

    private function askNextOfKin(array &$state, array $config, int $now, array &$did): void
    {
        $fireAt = $now + Schedule::NOK_WINDOW;
        $nok = $this->confirmedNok();
        $verifyTokens = [];
        $reached = false;

        if ($nok !== null) {
            [$token, $record] = Tokens::issue(Schedule::NOK_WINDOW + 30 * Schedule::DAY);
            $verifyTokens[] = $record;
            $reached = $this->outbox->send(
                (string) $nok['email'],
                $this->verifyEmail($config, $state, $nok, $token, $fireAt, false),
                'contact'
            );
        }

        // The owner is told too, with one more chance to check in.
        [$ownerToken, $ownerRecord] = Tokens::issue(Schedule::CHECKIN_TOKEN_TTL);
        $ownerTold = $this->outbox->toOwner(
            $this->ownerAddresses($config),
            Messages::ownerVerifyStarted($config, $this->url($config, 'checkin.php', ['t' => $ownerToken]), $nok, $fireAt)
        );

        if (!$reached && $ownerTold === []) {
            // No email is getting out at all. Wait for mail to work rather than escalate unseen.
            $did[] = 'Could not send any email. The escalation is waiting for mail to work.';
            return;
        }

        $state['cycle']['tokens'][] = $ownerRecord;
        $state['cycle']['stage'] = 'verify';
        $state['cycle']['verify'] = [
            'asked_at' => $now,
            'reached' => $reached,
            'reminded_at' => null,
            'tokens' => $verifyTokens,
            'response' => null,
            'responded_at' => null,
        ];
        $this->saveState($state);
        $this->store->log('cycle.verify', $reached
            ? 'No check-in after 72 hours. The next of kin has been asked.'
            : 'No check-in after 72 hours. The next of kin could not be emailed; the 72-hour wait has started anyway.');
        $did[] = $reached ? 'Asked the next of kin.' : 'Could not email the next of kin. Waiting 72 hours before notifying the others.';
    }

    private function remindNextOfKin(array &$state, array $config, int $now, array &$did): void
    {
        $nok = $this->confirmedNok();
        $plan = self::plan($state['cycle'], null);
        $fireAt = max($plan['verify']['at'] + Schedule::NOK_WINDOW, $now + Schedule::MIN_STEP_GAP);

        if ($nok !== null) {
            [$token, $record] = Tokens::issue(Schedule::NOK_WINDOW + 30 * Schedule::DAY);
            $state['cycle']['verify']['tokens'][] = $record;
            $sent = $this->outbox->send(
                (string) $nok['email'],
                $this->verifyEmail($config, $state, $nok, $token, $fireAt, true),
                'contact'
            );
            if ($sent) {
                $state['cycle']['verify']['reached'] = true;
            }
        }
        // Best effort: a next of kin who cannot be emailed must not hold the switch up for ever.
        $state['cycle']['verify']['reminded_at'] = $now;
        $this->saveState($state);
        $this->store->log('cycle.verify_reminder', 'The next of kin was reminded.');
        $did[] = 'Reminded the next of kin.';
    }

    /** @param string $reason 'timeout' or 'nok_wrong' */
    private function fire(array &$state, array $config, string $reason, array &$did): void
    {
        $state['cycle']['stage'] = 'fired';
        $state['cycle']['fired_at'] = Clock::now();
        $state['cycle']['fired_reason'] = $reason;
        $this->saveState($state);
        $this->store->log('cycle.fired', $reason === 'nok_wrong'
            ? 'The next of kin answered "Something\'s wrong". The switch has fired.'
            : 'Neither the owner nor the next of kin confirmed all is well. The switch has fired.');
        $did[] = 'The switch fired.';
        $this->deliverFired($state, $config, $did);
    }

    /** Send each confirmed contact their message, next of kin first. Retries anyone not yet reached. */
    private function deliverFired(array &$state, array $config, array &$did): void
    {
        $all = $this->contacts->all();
        foreach ($this->contacts->confirmed() as $contact) {
            if (isset($state['cycle']['notified'][$contact['id']])) {
                continue;
            }
            $vars = Messages::vars($config, $state, $all, $contact);
            if ($this->outbox->send((string) $contact['email'], Messages::fired($config, $contact, $vars), 'contact')) {
                $state['cycle']['notified'][$contact['id']] = Clock::now();
                $this->saveState($state);
                $this->store->log('cycle.notified', "{$contact['role_label']} ({$contact['name']}) was sent their message.");
                $did[] = "Sent {$contact['name']} their message.";
            } else {
                $did[] = "Could not send {$contact['name']} their message. Will try again on the next run.";
            }
        }

        if ($state['cycle']['owner_told_fired_at'] === null && $state['cycle']['notified'] !== []) {
            [$token, $record] = Tokens::issue(Schedule::ALL_CLEAR_TOKEN_TTL);
            $names = [];
            foreach ($all as $contact) {
                if (isset($state['cycle']['notified'][$contact['id']])) {
                    $names[] = $contact['name'];
                }
            }
            $state['cycle']['tokens'][] = $record;
            if ($this->outbox->toOwner(
                $this->ownerAddresses($config),
                Messages::ownerFired($this->url($config, 'checkin.php', ['t' => $token]), $names)
            )) {
                $state['cycle']['owner_told_fired_at'] = Clock::now();
            }
            $this->saveState($state);
        }
    }

    /**
     * End the current cycle because the owner is evidently here.
     * Anyone already contacted is told it was a false alarm.
     *
     * @return string[] names of the people told
     */
    private function closeCycle(array &$state, array $config): array
    {
        $cycle = $state['cycle'];
        if ($cycle === null) {
            return [];
        }
        $toTell = [];
        if (!empty($cycle['verify']['reached'])) {
            $nok = $this->contacts->nok();
            if ($nok !== null) {
                $toTell[$nok['id']] = true;
            }
        }
        foreach (array_keys($cycle['notified'] ?? []) as $id) {
            $toTell[$id] = true;
        }

        $state['cycle'] = null;
        if ($cycle['stage'] === 'fired') {
            $state['after_fire_at'] = Clock::now();
        }
        $state['all_clear_queue'] = array_values(array_unique(array_merge(
            (array) $state['all_clear_queue'],
            array_map('strval', array_keys($toTell))
        )));
        $this->saveState($state);

        return $this->sendAllClears($state, $config);
    }

    /** @return string[] names told this time */
    private function sendAllClears(array &$state, array $config): array
    {
        $told = [];
        $remaining = [];
        foreach ((array) $state['all_clear_queue'] as $id) {
            $contact = $this->contacts->find((string) $id);
            if ($contact === null) {
                continue;
            }
            if ($this->outbox->send((string) $contact['email'], Messages::allClear($config), 'contact')) {
                $told[] = $contact['name'];
                $this->store->log('cycle.all_clear', "{$contact['name']} was told it was a false alarm.");
            } else {
                $remaining[] = $id; // retried on the next run
            }
        }
        $state['all_clear_queue'] = $remaining;
        $this->saveState($state);
        return $told;
    }

    /** Jobs that are not part of a cycle. */
    private function housekeeping(array &$state, array $config, int $now, array &$did): void
    {
        // False-alarm notices that could not be sent earlier.
        if ($state['all_clear_queue'] !== []) {
            $told = $this->sendAllClears($state, $config);
            if ($told) {
                $did[] = 'Sent false-alarm notices to ' . implode(', ', $told) . '.';
            }
        }

        // Re-test the data folder once a day.
        $probe = $state['probe'];
        // A check made from the owner's browser stands in when the server cannot
        // reach itself, but only for 30 days: server settings change.
        $browserVerified = ($probe['result'] ?? '') === 'unknown'
            && !empty($probe['browser_ok_at'])
            && $probe['browser_ok_at'] + 30 * Schedule::DAY > $now;
        if (($probe['checked_at'] ?? 0) + Schedule::PROBE_EVERY <= $now) {
            $result = ($this->prober)($this->store, (string) ($config['base_url'] ?? ''));
            if ($result['result'] === 'unknown' && $browserVerified) {
                $result['browser_ok_at'] = $probe['browser_ok_at'];
            }
            $result['alerted_at'] = $probe['alerted_at'] ?? null;
            if ($result['result'] === 'exposed' && $result['alerted_at'] === null) {
                $this->outbox->toOwner($this->ownerAddresses($config), Messages::ownerDataExposed($this->url($config, 'index.php')));
                $result['alerted_at'] = $now;
                $this->store->log('probe.exposed', 'The data folder can be read from the web.');
                $did[] = 'Warning sent: the data folder can be read from the web.';
            } elseif ($result['result'] !== 'exposed') {
                $result['alerted_at'] = null;
            }
            $state['probe'] = $result;
            $this->saveState($state);
        }

        // Yearly: ask each contact to reconfirm.
        foreach ($this->contacts->confirmed() as $contact) {
            $since = (int) ($contact['reconfirmed_at'] ?? $contact['confirmed_at'] ?? 0);
            if ($since === 0 || $now < $since + Schedule::YEAR) {
                continue;
            }
            $asked = $contact['reconfirm_asked_at'] ?? null;
            $nudged = $contact['reconfirm_nudged_at'] ?? null;
            if ($asked === null || ($nudged === null && $now >= $asked + Schedule::RECONFIRM_NUDGE)) {
                if ($this->sendConfirmation($contact['id'], 'reconfirm')) {
                    $did[] = "Asked {$contact['name']} to reconfirm.";
                }
            }
        }

        // Yearly: prompt the owner to review what each contact holds.
        if ($state['armed'] && $state['review_at'] !== null && $now >= $state['review_at'] + Schedule::YEAR) {
            if (($state['review_asked_at'] ?? 0) < $state['review_at']) {
                if ($this->outbox->toOwner([(string) $config['owner_email']], Messages::ownerAnnualReview($this->url($config, 'index.php', ['p' => 'review']))) !== []) {
                    $state['review_asked_at'] = $now;
                    $this->saveState($state);
                    $did[] = 'Sent the yearly review prompt.';
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // Things the owner does
    // ------------------------------------------------------------------

    /** @return string[] reasons the switch cannot be armed; empty on success */
    public function arm(): array
    {
        return $this->store->locked(function (): array {
            $blocking = array_values(array_filter($this->checklist(), static fn (array $i): bool => !$i['ok'] && $i['blocking']));
            if ($blocking) {
                return array_column($blocking, 'label');
            }
            $now = Clock::now();
            $config = $this->config();
            $state = $this->state();
            $state['armed'] = true;
            $state['armed_at'] = $now;
            $state['paused_until'] = null;
            $state['cycle'] = null;
            $state['last_checkin_at'] = $now; // arming is proof of life
            $state['last_checkin_via'] = 'arming the switch';
            $state['next_due_at'] = $this->nextSlot($config, $now);
            $state['review_at'] = $now;
            $this->saveState($state);
            $this->store->log('switch.armed', 'The switch was armed.');
            $this->outbox->toOwner($this->ownerAddresses($config), Messages::ownerArmed($config, $state['next_due_at']));
            return [];
        });
    }

    /** @return string[] names told it was a false alarm */
    public function disarm(string $reason = 'You disarmed it from the dashboard.'): array
    {
        return $this->store->locked(function () use ($reason): array {
            $config = $this->config();
            $state = $this->state();
            if (!$state['armed']) {
                return [];
            }
            $told = $this->closeCycle($state, $config);
            $state['armed'] = false;
            $state['paused_until'] = null;
            $state['next_due_at'] = null;
            $this->saveState($state);
            $this->store->log('switch.disarmed', 'The switch was disarmed. ' . $reason);
            $this->outbox->toOwner($this->ownerAddresses($config), Messages::ownerDisarmed($reason));
            return $told;
        });
    }

    /** @return array{until: int, told: string[]}|null null if the switch is not armed */
    public function pause(int $days): ?array
    {
        return $this->store->locked(function () use ($days): ?array {
            $config = $this->config();
            $state = $this->state();
            if (!$state['armed']) {
                return null;
            }
            $days = min(Schedule::PAUSE_MAX_DAYS, max(1, $days));
            $now = Clock::now();
            $told = $this->closeCycle($state, $config);
            $state['last_checkin_at'] = $now; // pausing is proof of life
            $state['last_checkin_via'] = 'pausing the switch';
            $state['paused_until'] = $now + $days * Schedule::DAY;
            $this->saveState($state);
            $this->store->log('pause.started', "The switch was paused for {$days} days.");
            return ['until' => $state['paused_until'], 'told' => $told];
        });
    }

    public function resume(): void
    {
        $this->store->locked(function (): void {
            $state = $this->state();
            if ($state['paused_until'] === null) {
                return;
            }
            $now = Clock::now();
            $state['paused_until'] = null;
            $state['last_checkin_at'] = $now;
            $state['last_checkin_via'] = 'ending the pause';
            $state['next_due_at'] = $this->nextSlot($this->config(), $now);
            $this->saveState($state);
            $this->store->log('pause.ended', 'The pause was ended early.');
        });
    }

    /**
     * Record a check-in. This is the only thing that resets the timer.
     *
     * @return array{stage: ?string, told: string[], next_due_at: ?int, armed: bool, paused_until: ?int}
     */
    public function checkIn(string $via): array
    {
        return $this->store->locked(function () use ($via): array {
            $config = $this->config();
            $state = $this->state();
            $now = Clock::now();
            $stage = $state['cycle']['stage'] ?? null;
            $told = $this->closeCycle($state, $config);
            $state['last_checkin_at'] = $now;
            $state['last_checkin_via'] = $via;
            if ($state['armed']) {
                $state['next_due_at'] = $this->nextSlot($config, max($now, (int) $state['paused_until']));
            }
            $this->saveState($state);
            $this->store->log('checkin', 'Checked in by ' . $via . '.'
                . ($told ? ' False-alarm notices sent to ' . implode(', ', $told) . '.' : ''));
            return [
                'stage' => $stage,
                'told' => $told,
                'next_due_at' => $state['next_due_at'],
                'armed' => (bool) $state['armed'],
                'paused_until' => $state['paused_until'],
            ];
        });
    }

    /** Is this emailed check-in link still usable? Looking never counts as a check-in. */
    public function checkinTokenIsLive(string $token): bool
    {
        $cycle = $this->state()['cycle'];
        return $cycle !== null && Tokens::matches($token, (array) $cycle['tokens']);
    }

    /** Check in with an emailed link. Returns null if the link is spent or expired. */
    public function checkInByToken(string $token): ?array
    {
        return $this->store->locked(function () use ($token): ?array {
            if (!$this->checkinTokenIsLive($token)) {
                return null;
            }
            // Closing the cycle discards every link issued for it, which makes them single use.
            return $this->checkIn('the emailed link');
        });
    }

    /** The owner has reviewed what each contact holds. */
    public function recordReview(array $contactIds): void
    {
        $this->store->locked(function () use ($contactIds): void {
            $now = Clock::now();
            $all = true;
            foreach ($this->contacts->all() as $contact) {
                if (in_array($contact['id'], $contactIds, true)) {
                    $contact['has_needs_at'] = $now;
                    $this->contacts->save($contact);
                } elseif (($contact['status'] ?? '') === 'confirmed') {
                    $all = false;
                }
            }
            if ($all) {
                $state = $this->state();
                $state['review_at'] = $now;
                $this->saveState($state);
                $this->store->log('review.done', 'Yearly review completed.');
            }
        });
    }

    public function dismissAfterFireNotice(): void
    {
        $this->store->locked(function (): void {
            $state = $this->state();
            $state['after_fire_at'] = null;
            $this->saveState($state);
        });
    }

    // ------------------------------------------------------------------
    // The next of kin
    // ------------------------------------------------------------------

    public function verifyTokenIsLive(string $token): bool
    {
        $cycle = $this->state()['cycle'];
        return $cycle !== null
            && $cycle['stage'] === 'verify'
            && ($cycle['verify']['response'] ?? null) === null
            && Tokens::matches($token, (array) ($cycle['verify']['tokens'] ?? []));
    }

    /**
     * Record the next of kin's answer.
     *
     * @param string $answer 'fine' or 'wrong'
     * @return string|null 'fine', 'wrong', or null if the link is no longer active
     */
    public function nokAnswer(string $token, string $answer): ?string
    {
        if ($answer !== 'fine' && $answer !== 'wrong') {
            return null;
        }
        return $this->store->locked(function () use ($token, $answer): ?string {
            if (!$this->verifyTokenIsLive($token)) {
                return null;
            }
            $config = $this->config();
            $state = $this->state();
            $now = Clock::now();
            $state['cycle']['verify']['response'] = $answer;
            $state['cycle']['verify']['responded_at'] = $now;
            $state['cycle']['verify']['tokens'] = [];

            if ($answer === 'wrong') {
                $did = [];
                $this->fire($state, $config, 'nok_wrong', $did);
                return 'wrong';
            }

            // "All fine": this cycle ends and nobody else is contacted.
            $nok = $this->contacts->nok();
            $state['cycle'] = null;
            $state['next_due_at'] = $this->nextSlot($config, $now);
            $this->saveState($state);
            $this->store->log('cycle.vouched', 'The next of kin answered "All fine". Nobody else was contacted.');
            $this->outbox->toOwner($this->ownerAddresses($config), Messages::ownerVouched($config, $nok, $state['next_due_at']));
            return 'fine';
        });
    }

    // ------------------------------------------------------------------
    // Contacts: confirming, declining, reconfirming
    // ------------------------------------------------------------------

    /**
     * Email a contact the link that lets them confirm.
     *
     * @param string|null $purpose 'confirm', 'email_change' or 'reconfirm'; worked out if null
     */
    public function sendConfirmation(string $id, ?string $purpose = null): bool
    {
        return $this->store->locked(function () use ($id, $purpose): bool {
            $contact = $this->contacts->find($id);
            if ($contact === null) {
                return false;
            }
            $config = $this->config();
            $now = Clock::now();
            $purpose ??= !empty($contact['pending_email']) ? 'email_change' : 'confirm';
            $address = $purpose === 'email_change' ? (string) $contact['pending_email'] : (string) $contact['email'];

            [$token, $record] = Tokens::issue(Schedule::CONFIRM_TOKEN_TTL);
            $email = Messages::contactConfirm($config, $contact, $this->url($config, 'confirm.php', ['t' => $token]), $purpose);
            if (!$this->outbox->send($address, $email, 'contact')) {
                return false;
            }
            $contact['token'] = $record + ['purpose' => $purpose];
            $contact['confirm_sent_at'] = $now;
            if ($purpose === 'reconfirm') {
                if (($contact['reconfirm_asked_at'] ?? null) === null) {
                    $contact['reconfirm_asked_at'] = $now;
                } else {
                    $contact['reconfirm_nudged_at'] = $now;
                }
            } elseif ($purpose === 'confirm' && $contact['status'] === 'declined') {
                $contact['status'] = 'unconfirmed';
            }
            $this->contacts->save($contact);
            return true;
        });
    }

    /**
     * A contact pressed "Yes, I confirm" or "No, remove me".
     *
     * @return array{contact: array, accepted: bool}|null null if the link is no longer active
     */
    public function contactAnswer(string $token, bool $accept): ?array
    {
        return $this->store->locked(function () use ($token, $accept): ?array {
            $contact = $this->contacts->forToken($token);
            if ($contact === null) {
                return null;
            }
            $config = $this->config();
            $now = Clock::now();
            $purpose = (string) ($contact['token']['purpose'] ?? 'confirm');
            $contact['token'] = null;
            $extra = '';

            if ($accept) {
                if ($purpose === 'email_change' && !empty($contact['pending_email'])) {
                    $contact['email'] = $contact['pending_email'];
                }
                $contact['pending_email'] = null;
                $contact['status'] = 'confirmed';
                $contact['confirmed_at'] ??= $now;
                $contact['reconfirmed_at'] = $now;
                $contact['reconfirm_asked_at'] = null;
                $contact['reconfirm_nudged_at'] = null;
                $this->contacts->save($contact);
                $this->store->log('contact.confirmed', "{$contact['name']} confirmed as {$contact['role_label']}.");
            } elseif ($purpose === 'email_change') {
                // The new address said no. The confirmed address stays in use.
                $contact['pending_email'] = null;
                $this->contacts->save($contact);
                $this->store->log('contact.declined', "The new address for {$contact['name']} was declined. The old one is still in use.");
                $extra = 'Their previous address is still in use.';
            } else {
                $contact['status'] = 'declined';
                $contact['pending_email'] = null;
                $this->contacts->save($contact);
                $this->store->log('contact.declined', "{$contact['name']} declined to be {$contact['role_label']}.");

                // An armed switch must always have its required contacts.
                $state = $this->state();
                if ($state['armed'] && !$this->requiredContactsConfirmed()) {
                    $this->disarm("{$contact['name']} declined to be your " . Contacts::role($contact) . ', so a required contact is missing.');
                    $extra = 'Your switch has been disarmed, because it needs this role filled.';
                }
            }

            $this->outbox->toOwner($this->ownerAddresses($config), Messages::ownerContactAnswered($contact, $accept, $extra));
            return ['contact' => $contact, 'accepted' => $accept];
        });
    }

    public function requiredContactsConfirmed(): bool
    {
        return $this->confirmedNok() !== null && $this->contacts->confirmedOfKind('technical') !== [];
    }

    // ------------------------------------------------------------------
    // Status, for the dashboard, the weekly email and the health check
    // ------------------------------------------------------------------

    /**
     * What has to be true before the switch can be armed.
     *
     * @return array<int, array{key: string, label: string, ok: bool, blocking: bool, hint: string}>
     */
    public function checklist(): array
    {
        $state = $this->state();
        $config = $this->config();
        $now = Clock::now();
        $items = [];

        $ticked = $state['last_tick_at'] !== null && $now - $state['last_tick_at'] <= Schedule::CRON_STALE;
        $items[] = [
            'key' => 'scheduler',
            'label' => 'The scheduler is running',
            'ok' => $ticked,
            'blocking' => true,
            'hint' => $state['last_tick_at'] === null
                ? 'No run has been recorded yet. Add the crontab line shown in Settings.'
                : 'The last run was ' . relative($state['last_tick_at']) . '. Check the crontab line shown in Settings.',
        ];

        $probe = (string) ($state['probe']['result'] ?? 'unknown');
        $probeOk = in_array($probe, ['protected', 'outside'], true) || ($probe === 'unknown' && !empty($state['probe']['browser_ok_at']));
        $items[] = [
            'key' => 'data',
            'label' => 'The data folder cannot be read from the web',
            'ok' => $probeOk,
            'blocking' => true,
            'hint' => $probe === 'exposed'
                ? 'Files in the data folder can be opened in a browser. See "Protecting the data folder" in the README.'
                : 'The server could not test this by itself. Run the check from Settings.',
        ];

        $nok = $this->contacts->nok();
        $items[] = [
            'key' => 'nok',
            'label' => 'Your next of kin has confirmed',
            'ok' => $nok !== null && $nok['status'] === 'confirmed',
            'blocking' => true,
            'hint' => $nok === null ? 'Add your next of kin.' : self::contactHint($nok),
        ];

        $technical = array_values(array_filter($this->contacts->all(), static fn (array $c): bool => $c['kind'] === 'technical'));
        $items[] = [
            'key' => 'technical',
            'label' => 'Your technical 2IC has confirmed',
            'ok' => $this->contacts->confirmedOfKind('technical') !== [],
            'blocking' => true,
            'hint' => $technical === [] ? 'Add the person who would look after your clients.' : self::contactHint($technical[0]),
        ];

        $unwritten = [];
        $unticked = [];
        foreach ($this->contacts->confirmed() as $contact) {
            $text = ($contact['message']['subject'] ?? '') . ' ' . ($contact['message']['body'] ?? '')
                . ' ' . ($contact['verify_message']['body'] ?? '');
            if (Messages::hasMarker($text)) {
                $unwritten[] = $contact['name'];
            }
            if (empty($contact['has_needs_at'])) {
                $unticked[] = $contact['name'];
            }
        }
        $any = $this->contacts->confirmed() !== [];
        $items[] = [
            'key' => 'messages',
            'label' => 'Every message says where things are',
            'ok' => $any && $unwritten === [],
            'blocking' => true,
            'hint' => $any
                ? 'Still holding starter text in square brackets: ' . implode(', ', $unwritten) . '.'
                : 'Checked once your contacts have confirmed.',
        ];
        $items[] = [
            'key' => 'needs',
            'label' => 'Each contact has been given what they need',
            'ok' => $any && $unticked === [],
            'blocking' => true,
            'hint' => $any
                ? 'Not ticked yet: ' . implode(', ', $unticked) . '.'
                : 'Checked once your contacts have confirmed.',
        ];

        $items[] = [
            'key' => 'backup',
            'label' => 'A second email address for reminders',
            'ok' => !empty($config['backup_email']),
            'blocking' => false,
            'hint' => 'Recommended: an address with a different provider, in case your main one fails. Add it in Settings.',
        ];

        $items[] = [
            'key' => 'telegram',
            'label' => 'A second way to reach you: Telegram',
            'ok' => Outbox::telegramReady($config),
            'blocking' => false,
            'hint' => 'Recommended: reminders also go to Telegram, and your next of kin is not asked until a reminder has reached you both ways. Set it up in Settings.',
        ];

        return $items;
    }

    private static function contactHint(array $contact): string
    {
        return match ($contact['status']) {
            'declined' => "{$contact['name']} declined. Choose someone else.",
            'confirmed' => '',
            default => empty($contact['confirm_sent_at'])
                ? "Send {$contact['name']} their confirmation email."
                : "Waiting for {$contact['name']} to confirm. The email went " . relative($contact['confirm_sent_at']) . '.',
        };
    }

    /**
     * Problems worth the owner's attention right now.
     *
     * @return array<int, array{key: string, level: string, text: string}> level is 'bad' or 'warn'
     */
    public function warnings(): array
    {
        $state = $this->state();
        $config = $this->config();
        $mail = $this->store->read('mail');
        $now = Clock::now();
        $out = [];

        foreach ($this->store->recoveredFiles() as $name) {
            $out[] = ['key' => 'store', 'level' => 'bad', 'text' => "{$name}.json was damaged and the last good copy is in use. Check what changed recently, then save any setting to rewrite the file."];
        }

        if ($state['last_tick_at'] === null) {
            $out[] = ['key' => 'scheduler', 'level' => 'bad', 'text' => 'The scheduler has never run. Nothing will be sent until it does.'];
        } elseif ($now - $state['last_tick_at'] > Schedule::CRON_STALE) {
            $out[] = ['key' => 'scheduler', 'level' => 'bad', 'text' => 'The scheduler last ran ' . relative($state['last_tick_at']) . '. While it is stopped, the switch cannot send anything.'];
        }

        if (Outbox::telegramReady($config) && !empty($mail['telegram_error'])) {
            $error = $mail['telegram_error'];
            $out[] = ['key' => 'telegram', 'level' => 'bad', 'text' => 'The last Telegram message could not be sent (' . relative((int) $error['at']) . '): ' . $error['error']];
        }

        $cycle = $state['cycle'];
        if ($state['armed'] && ($cycle['stage'] ?? null) === 'checkin' && !empty($cycle['chased_at'])
            && ($missing = $this->unreachedChannels($cycle, $config)) !== []) {
            $out[] = ['key' => 'channels', 'level' => 'bad', 'text' => 'You have not checked in, and your reminders have not reached you by '
                . self::channelNames($missing) . '. Your next of kin will be asked once they do, or a week after the usual 72 hours.'];
        }

        if (!empty($mail['last_send_error'])) {
            $error = $mail['last_send_error'];
            $out[] = ['key' => 'mail', 'level' => 'bad', 'text' => 'The last email could not be sent (' . relative((int) $error['at']) . '): ' . $error['error']];
        }

        $probe = (string) ($state['probe']['result'] ?? '');
        if ($probe === 'exposed') {
            $out[] = ['key' => 'data', 'level' => 'bad', 'text' => 'Files in the data folder can be opened in a browser.'];
        } elseif ($probe === 'unknown' && empty($state['probe']['browser_ok_at'])) {
            $out[] = ['key' => 'data', 'level' => 'warn', 'text' => 'The server could not check whether the data folder is blocked from the web.'];
        }

        if ($state['armed'] && !$this->requiredContactsConfirmed()) {
            $out[] = ['key' => 'required', 'level' => 'bad', 'text' => 'A required contact is missing or unconfirmed.'];
        }

        foreach ($this->contacts->all() as $contact) {
            $name = $contact['name'] ?: $contact['email'];
            if ($contact['status'] === 'declined') {
                $out[] = ['key' => 'contact', 'level' => 'warn', 'text' => "{$name} declined and will not be contacted."];
            } elseif ($contact['status'] === 'unconfirmed') {
                $out[] = ['key' => 'contact', 'level' => 'warn', 'text' => "{$name} has not confirmed, so will not be contacted."];
            } else {
                if (!empty($contact['pending_email'])) {
                    $out[] = ['key' => 'contact', 'level' => 'warn', 'text' => "{$name} has not confirmed their new address. The old one is still in use."];
                }
                $asked = $contact['reconfirm_asked_at'] ?? null;
                if ($asked !== null && $now - $asked > Schedule::RECONFIRM_NUDGE) {
                    $out[] = ['key' => 'contact', 'level' => 'warn', 'text' => "{$name} has not answered their yearly reconfirmation. Check their address still works."];
                }
                if ($state['armed'] && Messages::hasMarker(($contact['message']['body'] ?? '') . ($contact['verify_message']['body'] ?? ''))) {
                    $out[] = ['key' => 'contact', 'level' => 'bad', 'text' => "The message for {$name} still holds starter text in square brackets."];
                }
            }
        }

        if ($state['armed'] && $state['review_at'] !== null && $now >= $state['review_at'] + Schedule::YEAR) {
            $out[] = ['key' => 'review', 'level' => 'warn', 'text' => 'Your yearly review is due: confirm each contact still has what they need.'];
        }

        if (!empty($config['base_url']) && !str_starts_with((string) $config['base_url'], 'https://')) {
            $out[] = ['key' => 'https', 'level' => 'warn', 'text' => 'This install is not using HTTPS. Links in its emails can be read in transit.'];
        }

        return $out;
    }

    /**
     * The timetable for a cycle: when each step happened, or when it will.
     * Used both to decide what is due and to draw the track on the dashboard.
     *
     * @return array<string, array{at: int, done: bool}>
     */
    public static function plan(?array $cycle, ?int $nextDue): array
    {
        $start = $cycle['started_at'] ?? $nextDue;
        if ($start === null) {
            return [];
        }
        $gap = Schedule::MIN_STEP_GAP;
        $reminders = (array) ($cycle['reminders'] ?? []);
        $plan = ['email' => ['at' => (int) $start, 'done' => $cycle !== null]];

        $previous = null;
        foreach (Schedule::OWNER_REMINDERS as $i => $offset) {
            $at = $reminders[$i] ?? max($start + $offset, $previous === null ? 0 : $previous + $gap);
            $plan['reminder' . ($i + 1)] = ['at' => (int) $at, 'done' => isset($reminders[$i])];
            $previous = $at;
        }

        $verify = $cycle['verify'] ?? null;
        $asked = $verify['asked_at'] ?? max($start + Schedule::OWNER_WINDOW, $previous + $gap);
        $plan['verify'] = ['at' => (int) $asked, 'done' => isset($verify['asked_at'])];

        $reminded = $verify['reminded_at'] ?? $asked + Schedule::NOK_REMINDER;
        $plan['nok_reminder'] = ['at' => (int) $reminded, 'done' => isset($verify['reminded_at'])];

        $fired = $cycle['fired_at'] ?? max($asked + Schedule::NOK_WINDOW, $reminded + $gap);
        $plan['fire'] = ['at' => (int) $fired, 'done' => isset($cycle['fired_at'])];

        return $plan;
    }

    /**
     * Everything the dashboard needs to describe the switch.
     *
     * mode: setup | armed | paused | overdue | verify | fired
     */
    public function status(): array
    {
        $state = $this->state();
        $cycle = $state['cycle'];

        if (!$state['armed']) {
            $mode = 'setup';
        } elseif ($state['paused_until'] !== null) {
            $mode = 'paused';
        } elseif ($cycle === null) {
            $mode = 'armed';
        } else {
            $mode = ['checkin' => 'overdue', 'verify' => 'verify', 'fired' => 'fired'][$cycle['stage']] ?? 'armed';
        }

        return [
            'mode' => $mode,
            'state' => $state,
            'plan' => $state['armed'] && $state['paused_until'] === null ? self::plan($cycle, $state['next_due_at']) : [],
        ];
    }

    /**
     * For external monitors: is anything broken that the owner would not
     * otherwise hear about? Deliberately says nothing private.
     *
     * @return array{ok: bool, armed: bool, problems: string[]}
     */
    public function health(): array
    {
        $problems = [];
        foreach ($this->warnings() as $warning) {
            if ($warning['level'] === 'bad') {
                $problems[$warning['key']] = true;
            }
        }
        $state = $this->state();
        return [
            'ok' => $problems === [],
            'armed' => (bool) $state['armed'],
            'problems' => array_keys($problems),
        ];
    }

    /**
     * The emails a contact would receive, filled in as of now.
     *
     * @return array<string, Email>
     */
    public function preview(array $contact): array
    {
        $config = $this->config();
        $state = $this->state();
        $vars = Messages::vars($config, $state, $this->contacts->all(), $contact);
        $out = [];
        if ($contact['kind'] === 'nok') {
            $out['When you miss a check-in and its reminders'] = Messages::nokVerify(
                $config,
                $contact,
                $vars,
                $this->url($config, 'respond.php', ['t' => 'EXAMPLE', 'a' => 'fine']),
                $this->url($config, 'respond.php', ['t' => 'EXAMPLE', 'a' => 'wrong']),
                Clock::now() + Schedule::NOK_WINDOW,
                false
            );
        }
        $out['When the switch fires'] = Messages::fired($config, $contact, $vars);
        return $out;
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    public function saveState(array $state): void
    {
        $this->store->write('state', $state);
    }

    public function nextSlot(array $config, int $after): int
    {
        return Schedule::nextSlot(
            $after,
            (string) ($config['timezone'] ?? 'UTC'),
            (int) ($config['slot_dow'] ?? 1),
            (int) ($config['slot_hour'] ?? 9)
        );
    }

    /** @return string[] */
    public function ownerAddresses(array $config): array
    {
        return array_values(array_filter([(string) ($config['owner_email'] ?? ''), (string) ($config['backup_email'] ?? '')]));
    }

    public function url(array $config, string $page, array $query = []): string
    {
        return rtrim((string) ($config['base_url'] ?? ''), '/') . '/' . $page . ($query ? '?' . http_build_query($query) : '');
    }

    private function confirmedNok(): ?array
    {
        $nok = $this->contacts->nok();
        return $nok !== null && $nok['status'] === 'confirmed' ? $nok : null;
    }

    private function verifyEmail(array $config, array $state, array $nok, string $token, int $fireAt, bool $reminder): Email
    {
        return Messages::nokVerify(
            $config,
            $nok,
            Messages::vars($config, $state, $this->contacts->all(), $nok),
            $this->url($config, 'respond.php', ['t' => $token, 'a' => 'fine']),
            $this->url($config, 'respond.php', ['t' => $token, 'a' => 'wrong']),
            $fireAt,
            $reminder
        );
    }
}
