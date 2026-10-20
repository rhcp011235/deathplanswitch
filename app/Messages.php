<?php
declare(strict_types=1);

namespace DPS;

/**
 * Every email the app sends, in one file.
 *
 * Two sorts of wording live here:
 *
 *  - Starter text for the messages each contact receives when the switch
 *    fires. The owner edits these. They are a convenience, not advice.
 *  - Fixed wording for the app's own emails (check-ins, confirmations,
 *    false alarms), which must stay accurate whatever the owner writes.
 */
final class Messages
{
    /**
     * Starter text carries a marker like "[write here: ...]" wherever the
     * owner has to supply a pointer. The switch will not arm while one is
     * left in a message, so the starter text can never be sent as it stands.
     */
    public const MARKER = '/\[write here:[^\]]*\]/i';

    public const PLACEHOLDERS = [
        '{owner_name}' => 'Your name',
        '{your_name}' => 'The name of the person receiving the message',
        '{days}' => 'Whole days since your last check-in',
        '{last_checkin_date}' => 'The date of your last check-in',
        '{nok_details}' => 'Next of kin: name, email, phone',
        '{technical_details}' => 'Technical contact: name, email, phone',
        '{contacts_list}' => 'Everyone else on your list, one per line',
    ];

    /** @return array{subject: string, body: string} */
    public static function defaults(string $kind): array
    {
        return match ($kind) {
            'nok' => [
                'subject' => '{owner_name} has stopped checking in: what happens now',
                'body' => "{owner_name} set up Death Plan Switch so that the right people would be told if they became unavailable. "
                    . "They have not checked in for {days} days, so the people they chose have now been sent their instructions.\n\n"
                    . "Those people are:\n{contacts_list}\n\n"
                    . "The documents that give you authority to act are kept here: [write here: where the authority documents are kept, and who to call]",
            ],
            'technical' => [
                'subject' => '{owner_name} has stopped checking in: please read',
                'body' => "{owner_name} set up Death Plan Switch so that you would be told if they became unavailable. That has now happened.\n\n"
                    . "They asked you to look after their clients' websites. The instructions and access details are here: "
                    . "[write here: where the handover document is, how to obtain its password, and what clients need first]\n\n"
                    . "Next of kin: {nok_details}",
            ],
            'legal' => [
                'subject' => 'Death Plan Switch has fired for {owner_name}',
                'body' => "{owner_name} listed you as their legal contact in Death Plan Switch. The switch has fired because they have not "
                    . "checked in and their next of kin has not confirmed they are well.\n\n"
                    . "Next of kin: {nok_details}\nTechnical contact: {technical_details}",
            ],
            default => [
                'subject' => '{owner_name} has stopped checking in',
                'body' => "{owner_name} listed you as a contact in Death Plan Switch, so that you would be told if they became unavailable. "
                    . "That has now happened: they have not checked in for {days} days.\n\n"
                    . "[write here: what this person needs to know or do]\n\n"
                    . "Next of kin: {nok_details}",
            ],
        };
    }

    /** The question put to the next of kin at the verify stage. */
    public static function verifyDefault(): array
    {
        return [
            'subject' => 'Is {owner_name} all right?',
            'body' => "{owner_name} hasn't checked in with Death Plan Switch for {days} days. You're listed as their next of kin. Is everything all right?",
        ];
    }

    public static function hasMarker(string $text): bool
    {
        return preg_match(self::MARKER, $text) === 1;
    }

    /** A gentle check for the "pointers only" rule. It cannot be exact, so it only ever warns. */
    public static function looksLikeLogin(string $text): bool
    {
        return preg_match('/\b(pass(word|phrase|code)?|pwd|pin|login|username)\s*(is|=|:)\s*\S+/i', $text) === 1;
    }

    public static function fill(string $text, array $vars): string
    {
        return strtr($text, $vars);
    }

    /** Values for the placeholders, as they stand right now, for one recipient. */
    public static function vars(array $config, array $state, array $contacts, ?array $recipient): array
    {
        $last = $state['last_checkin_at'] ?? null;
        $days = $last ? max(0, (int) floor((Clock::now() - $last) / Schedule::DAY)) : 0;

        $nok = null;
        $technical = null;
        $others = [];
        foreach ($contacts as $contact) {
            if (($contact['status'] ?? '') !== 'confirmed') {
                continue;
            }
            if ($contact['kind'] === 'nok') {
                $nok ??= $contact;
            }
            if ($contact['kind'] === 'technical') {
                $technical ??= $contact;
            }
            if ($recipient === null || $contact['id'] !== $recipient['id']) {
                $others[] = Contacts::line($contact);
            }
        }

        return [
            '{owner_name}' => (string) ($config['owner_name'] ?? ''),
            '{your_name}' => (string) ($recipient['name'] ?? ''),
            '{days}' => (string) $days,
            '{last_checkin_date}' => $last ? when($last, (string) ($config['timezone'] ?? 'UTC'), 'l j F Y') : 'an unknown date',
            '{nok_details}' => Contacts::details($nok),
            '{technical_details}' => Contacts::details($technical),
            '{contacts_list}' => $others ? implode("\n", $others) : '(nobody else is listed)',
        ];
    }

    // ---------------------------------------------------------------- owner

    public static function testCode(string $code): Email
    {
        return (new Email('Death Plan Switch: your confirmation code is ' . $code))
            ->p('Your Death Plan Switch can send email. To confirm this address reaches you, enter this code on the page you have open:')
            ->p($code)
            ->note('The code works for 30 minutes. If you did not ask for it, you can ignore this email.');
    }

    public static function smtpTest(): Email
    {
        return (new Email('Death Plan Switch: mail settings test'))
            ->p('This is a test from your Death Plan Switch. If you are reading it, the new mail settings work and have been saved.');
    }

    public static function telegramTest(): Email
    {
        return (new Email('Death Plan Switch: Telegram is linked'))
            ->p('This is a test from your Death Plan Switch. Your check-in emails and reminders will now come here too. A button press on either one checks you in.');
    }

    /**
     * Sent on a channel that has not yet reached the owner this week. The
     * next of kin waits until it does, or until $holdUntil.
     *
     * @param string[] $reached the channels that have reached the owner, as names
     */
    public static function ownerChase(array $config, string $url, array $reached, int $holdUntil): Email
    {
        $deadline = when($holdUntil, (string) ($config['timezone'] ?? 'UTC'), 'l j F \a\t g:ia');
        return (new Email('Check in now: Death Plan Switch'))
            ->p('You have not checked in this week, and your reminders have so far reached you only by '
                . implode(' and ', $reached) . '. Your next of kin is not asked until a reminder has reached you every way you set up.')
            ->link('Open the check-in page', $url)
            ->p("Once this message is delivered, or by {$deadline} at the latest, your next of kin will be asked whether everything is all right.");
    }

    /**
     * The weekly check-in, and its reminders.
     *
     * @param int $number 0 for the weekly email, 1 for the first reminder, 2 for the last
     * @param string[] $warnings things on the dashboard that need attention
     */
    public static function checkin(array $config, string $url, int $number, int $verifyAt, ?array $nok, array $warnings): Email
    {
        $tz = (string) ($config['timezone'] ?? 'UTC');
        $who = $nok['name'] ?? 'your next of kin';
        $deadline = when($verifyAt, $tz, 'l j F \a\t g:ia');

        if ($number === 0) {
            // The date keeps each week's email distinct, so mail apps do not fold it into last week's.
            $email = (new Email('Check in with Death Plan Switch: ' . when(Clock::now(), $tz, 'l j F')))
                ->p('This is your weekly check-in. Open the page and press the button to confirm you are here.')
                ->link('Open the check-in page', $url)
                ->p("Opening the page is not enough: press the button. If you have not checked in by {$deadline}, {$who} will be asked whether everything is all right.");
        } elseif ($number === 1) {
            $email = (new Email('Reminder: check in with Death Plan Switch'))
                ->p('You have not checked in this week. Open the page and press the button to confirm you are here.')
                ->link('Open the check-in page', $url)
                ->p("If you have not checked in by {$deadline}, {$who} will be asked whether everything is all right.");
        } else {
            $email = (new Email('Last reminder: check in with Death Plan Switch'))
                ->p("This is the last reminder. If you have not checked in by {$deadline}, {$who} will be asked whether everything is all right.")
                ->link('Open the check-in page', $url);
        }

        $status = 'Your switch is armed.';
        if ($warnings) {
            $status .= ' ' . (count($warnings) === 1 ? 'One thing needs' : count($warnings) . ' things need')
                . " your attention:\n- " . implode("\n- ", $warnings);
        }
        return $email->note($status . "\nIf this weekly email ever stops arriving, your switch has stopped working. Check your server.");
    }

    public static function ownerVerifyStarted(array $config, string $url, ?array $nok, int $fireAt): Email
    {
        $who = $nok['name'] ?? 'Your next of kin';
        $deadline = when($fireAt, (string) ($config['timezone'] ?? 'UTC'), 'l j F \a\t g:ia');
        return (new Email('Death Plan Switch has asked your next of kin if you are all right'))
            ->p("You did not check in, so {$who} has now been asked whether everything is all right.")
            ->p('You can still check in. If you do, they will be told it was a false alarm.')
            ->link('Open the check-in page', $url)
            ->p("If neither of you confirms you are well by {$deadline}, your other contacts will receive their messages.");
    }

    /** @param string[] $names who has been written to */
    public static function ownerFired(string $url, array $names): Email
    {
        return (new Email('Death Plan Switch has fired'))
            ->p('Your switch has fired. Messages have been sent to: ' . implode(', ', $names) . '.')
            ->p('If you are reading this, press the button on this page. Everyone who was contacted will be told it was a false alarm.')
            ->link('Tell them I am here', $url);
    }

    public static function ownerArmed(array $config, int $firstEmailAt): Email
    {
        $first = when($firstEmailAt, (string) ($config['timezone'] ?? 'UTC'), 'l j F \a\t g:ia');
        return (new Email('Your Death Plan Switch is armed'))
            ->p("Your switch is armed. The first check-in email arrives on {$first}, and one follows at the same time every week.")
            ->p('Each email links to a page with a button. Pressing the button is the check-in.')
            ->p('If you miss one: you get reminders for 72 hours, then your next of kin is asked whether everything is all right, and 72 hours after that your other contacts receive their messages.')
            ->note('If the weekly email ever stops arriving, your switch has stopped working. Check your server.');
    }

    public static function ownerDisarmed(string $reason): Email
    {
        return (new Email('Your Death Plan Switch is no longer armed'))
            ->p('Your switch has been disarmed. ' . $reason)
            ->p('While it is disarmed, no check-in emails are sent and nobody will be contacted. Sign in to arm it again.');
    }

    public static function ownerPauseEnded(array $config, int $nextAt): Email
    {
        $next = when($nextAt, (string) ($config['timezone'] ?? 'UTC'), 'l j F \a\t g:ia');
        return (new Email('Your Death Plan Switch is active again'))
            ->p("The pause has ended and your switch is armed. Your next check-in email arrives on {$next}.");
    }

    public static function ownerVouched(array $config, ?array $nok, int $nextAt): Email
    {
        $who = $nok['name'] ?? 'Your next of kin';
        $next = when($nextAt, (string) ($config['timezone'] ?? 'UTC'), 'l j F \a\t g:ia');
        return (new Email("{$who} confirmed you are all right"))
            ->p("You missed this week's check-in, so {$who} was asked whether everything is all right. They answered that all is fine, and nobody else has been contacted.")
            ->p("Your next check-in email arrives on {$next}.");
    }

    public static function ownerContactAnswered(array $contact, bool $accepted, string $extra = ''): Email
    {
        $name = $contact['name'] ?: $contact['email'];
        $role = Contacts::role($contact);
        if ($accepted) {
            return (new Email("{$name} has confirmed as your {$role}"))
                ->p("{$name} confirmed their email address and their role as your {$role}." . ($extra !== '' ? ' ' . $extra : ''));
        }
        return (new Email("{$name} has declined to be your {$role}"))
            ->p("{$name} declined, and will not be contacted by your switch. Sign in to choose someone else." . ($extra !== '' ? ' ' . $extra : ''));
    }

    public static function ownerAnnualReview(string $url): Email
    {
        return (new Email('Death Plan Switch: your yearly review is due'))
            ->p('Once a year, check that each of your contacts still has what they need: the right document, the right password, the right phone number.')
            ->link('Sign in to review your contacts', $url);
    }

    public static function ownerDataExposed(string $url): Email
    {
        return (new Email('Death Plan Switch: your data folder can be read from the web'))
            ->p('A routine check found that files in the data folder of your Death Plan Switch can be opened in a browser. Those files hold your contacts and their messages.')
            ->p('This usually happens after a server move or a web server change. The README explains how to block access. Fix it as soon as you can.')
            ->link('Sign in', $url);
    }

    // -------------------------------------------------------------- contacts

    public static function contactConfirm(array $config, array $contact, string $url, string $purpose): Email
    {
        $owner = (string) $config['owner_name'];
        $role = Contacts::role($contact);

        if ($purpose === 'reconfirm') {
            $email = (new Email("Still happy to be {$owner}'s {$role}?"))
                ->p("About a year ago you agreed to be {$owner}'s {$role} in Death Plan Switch. Please confirm that this email address still reaches you and that you are still willing.");
        } else {
            $email = (new Email("{$owner} has listed you as their {$role}"))
                ->p("{$owner} uses Death Plan Switch, a small app on their own server. Every week it asks {$owner} to check in. If they stop, it emails the people they have chosen. They have listed you as their {$role}.");
        }

        if ($contact['kind'] === 'nok') {
            $email->p("What this means for you: if {$owner} misses a check-in and three days of reminders, you would get one email asking whether everything is all right, with two buttons to answer.");
        } else {
            $email->p("What this means for you: if {$owner} stops checking in and their next of kin cannot confirm they are well, you would get one email from this address telling you what {$owner} needs you to do.");
        }

        return $email
            ->link('Open the confirmation page', $url)
            ->p("To make sure a future message is not mistaken for spam, add this email address to your contacts. If you have questions, reply to this email and it will reach {$owner}.")
            ->note('This link works for 30 days. If you do nothing, you will not be contacted again unless ' . $owner . ' sends a new request.');
    }

    public static function nokVerify(array $config, array $contact, array $vars, string $fineUrl, string $wrongUrl, int $fireAt, bool $reminder): Email
    {
        $owner = (string) $config['owner_name'];
        $message = $contact['verify_message'] ?? self::verifyDefault();
        $deadline = when($fireAt, (string) ($config['timezone'] ?? 'UTC'), 'l j F \a\t g:ia')
            . ' (' . self::zoneLabel((string) ($config['timezone'] ?? 'UTC')) . ')';

        $email = (new Email(($reminder ? 'Reminder: ' : '') . self::fill((string) $message['subject'], $vars)))
            ->prose(self::fill((string) $message['body'], $vars))
            ->link('All fine', $fineUrl)
            ->link("Something's wrong", $wrongUrl)
            ->p("Each link opens a page where you confirm your answer. If neither you nor {$owner} answers by {$deadline}, the other people {$owner} chose will be sent their instructions.");

        return $email->note(self::footer($config, $vars));
    }

    public static function fired(array $config, array $contact, array $vars): Email
    {
        $message = $contact['message'] ?? self::defaults((string) $contact['kind']);
        return (new Email(self::fill((string) $message['subject'], $vars)))
            ->prose(self::fill((string) $message['body'], $vars))
            ->note(self::footer($config, $vars));
    }

    public static function allClear(array $config): Email
    {
        $owner = (string) $config['owner_name'];
        return (new Email("False alarm: {$owner} has checked in"))
            ->p("{$owner} has checked in with Death Plan Switch. The earlier message was a false alarm, and you do not need to do anything.")
            ->p("{$owner} will be in touch. You can reply to this email to reach them.");
    }

    // ------------------------------------------------------------------
    // Texts and calls to contacts
    //
    // Short, and never the owner's own message: that goes by email only,
    // so that it is not left on a lock screen or read out to a voicemail.
    // ------------------------------------------------------------------

    /**
     * @param string|null $fineUrl  null for the version without links, sent
     *                              if the text service refuses links
     */
    public static function textVerify(array $config, array $nok, ?string $fineUrl, ?string $wrongUrl): string
    {
        $owner = (string) $config['owner_name'];
        $text = "{$owner} has missed a weekly check-in with Death Plan Switch and has not answered the reminders. Is everything all right?";
        if ($fineUrl !== null && $wrongUrl !== null) {
            return $text . "\n\nAll fine: {$fineUrl}\n\nSomething's wrong: {$wrongUrl}";
        }
        return $text . " Please answer the email sent to {$nok['email']}.";
    }

    public static function callVerify(array $config, array $nok): string
    {
        $owner = (string) $config['owner_name'];
        return "Hello {$nok['name']}. This is an automated call from {$owner}'s Death Plan Switch. "
            . "{$owner} has missed a weekly check-in and has not answered the reminders. "
            . "Please check your email and your text messages, and answer whether everything is all right.";
    }

    public static function textFired(array $config, array $contact): string
    {
        $owner = (string) $config['owner_name'];
        return "{$owner} has stopped checking in with Death Plan Switch, and nobody has confirmed they are well. "
            . "{$owner} left you a message, which has been emailed to {$contact['email']}. Please read it.";
    }

    public static function callFired(array $config, array $contact): string
    {
        $owner = (string) $config['owner_name'];
        return "Hello {$contact['name']}. This is an automated call from {$owner}'s Death Plan Switch. "
            . "{$owner} has stopped checking in, and nobody has confirmed they are well. "
            . "{$owner} left you a message, which has been emailed to you. Please read it.";
    }

    public static function textAllClear(array $config): string
    {
        $owner = (string) $config['owner_name'];
        return "False alarm: {$owner} has checked in with Death Plan Switch and is fine. You do not need to do anything.";
    }

    public static function callAllClear(array $config, array $contact): string
    {
        $owner = (string) $config['owner_name'];
        return "Hello {$contact['name']}. This is {$owner}'s Death Plan Switch again. It was a false alarm. "
            . "{$owner} has checked in and is fine. You do not need to do anything.";
    }

    public static function textTest(): string
    {
        return 'This is a test from your Death Plan Switch. Texts to your contacts will look like this.';
    }

    public static function callTest(array $config): string
    {
        return 'This is a test call from ' . $config['owner_name'] . "'s Death Plan Switch. Calls to your contacts will sound like this.";
    }

    private static function footer(array $config, array $vars): string
    {
        $owner = (string) $config['owner_name'];
        return "Death Plan Switch is software {$owner} runs on their own server. It sent this automatically because {$owner} "
            . "last checked in on {$vars['{last_checkin_date}']} and has not answered its emails since. It cannot know why.";
    }

    private static function zoneLabel(string $tz): string
    {
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('T') . ', ' . $tz;
        } catch (\Throwable) {
            return 'UTC';
        }
    }
}
