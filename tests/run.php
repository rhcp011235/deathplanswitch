<?php
/**
 * Death Plan Switch: test suite.
 *
 *   php tests/run.php
 *
 * No test framework is needed. Each test builds a fresh install in a
 * temporary folder, replaces the mail server with a recorder and the clock
 * with a simulated one, then runs the app's real code hour by hour, the way
 * cron would.
 */
declare(strict_types=1);

namespace DPS\Tests;

use DPS\Clock;
use DPS\Contacts;
use DPS\Email;
use DPS\Engine;
use DPS\Messages;
use DPS\Messenger;
use DPS\Outbox;
use DPS\Schedule;
use DPS\Store;
use DPS\StoreException;
use DPS\Tokens;
use DPS\Transport;

if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

const H = 3600;
const D = 86400;

/** Records email instead of sending it, and can be told to fail. */
final class Recorder implements Transport
{
    /** @var array<int, array{at: int, to: string[], subject: string, text: string, html: string, from: string, reply: string}> */
    public array $sent = [];
    /** @var null|callable(string[]): bool return true to fail this send */
    public $failWhen = null;

    public function send(array $smtp, array $to, string $fromName, string $replyTo, Email $email): void
    {
        if ($this->failWhen !== null && ($this->failWhen)($to)) {
            throw new \RuntimeException('Simulated mail failure');
        }
        $this->sent[] = [
            'at' => Clock::now(), 'to' => $to, 'subject' => $email->subject,
            'text' => $email->text(), 'html' => $email->html(), 'from' => $fromName, 'reply' => $replyTo,
        ];
    }
}

/** Records Telegram messages instead of sending them, and can be told to fail. */
final class ChatRecorder implements Messenger
{
    /** @var array<int, array{at: int, chat: string, text: string, button: ?array}> */
    public array $sent = [];
    public bool $down = false;

    public function send(array $settings, string $text, ?array $button): void
    {
        if ($this->down) {
            throw new \RuntimeException('Simulated Telegram failure');
        }
        $this->sent[] = ['at' => Clock::now(), 'chat' => (string) $settings['chat_id'], 'text' => $text, 'button' => $button];
    }

    /** @return array<int, array> messages whose first line contains $title */
    public function titled(string $title): array
    {
        return array_values(array_filter($this->sent, static fn (array $m): bool => str_contains(strtok($m['text'], "\n"), $title)));
    }
}

/** One throwaway install. */
final class World
{
    public string $dir;
    public Store $store;
    public Recorder $mail;
    public ChatRecorder $chat;
    public Engine $engine;
    public string $probe = 'protected';

    public const OWNER = 'owner@example.test';
    public const BACKUP = 'owner@backup.test';
    public const NOK = 'kin@example.test';
    public const TECH = 'tech@example.test';
    public const LEGAL = 'legal@example.test';

    /** @param string $start local time in Australia/Sydney */
    public function __construct(string $start = '2026-10-07 13:00')
    {
        $this->dir = sys_get_temp_dir() . '/dps-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        Clock::set((new \DateTimeImmutable($start, new \DateTimeZone('Australia/Sydney')))->getTimestamp());
        $this->store = new Store($this->dir);
        $this->mail = new Recorder();
        $this->chat = new ChatRecorder();
        $this->engine = new Engine(
            $this->store,
            new Outbox($this->store, $this->mail, $this->chat),
            fn (): array => ['result' => $this->probe, 'detail' => 'test', 'checked_at' => Clock::now()]
        );
        $this->store->write('config', [
            'owner_name' => 'Sam Rivers',
            'owner_email' => self::OWNER,
            'backup_email' => self::BACKUP,
            'timezone' => 'Australia/Sydney',
            'slot_dow' => 1,
            'slot_hour' => 9,
            'base_url' => 'https://switch.example.test',
            'smtp' => ['from_email' => 'switch@example.test'],
            'installed_at' => Clock::now(),
        ]);
    }

    public function __destruct()
    {
        foreach (scandir($this->dir) ?: [] as $name) {
            if (is_file($this->dir . '/' . $name)) {
                unlink($this->dir . '/' . $name);
            }
        }
        @rmdir($this->dir);
    }

    public function contact(string $kind, string $name, string $email, bool $ready = true): array
    {
        $contact = Contacts::blank($kind);
        $contact['name'] = $name;
        $contact['email'] = $email;
        if ($ready) {
            $contact['status'] = 'confirmed';
            $contact['confirmed_at'] = $contact['reconfirmed_at'] = Clock::now();
            $contact['has_needs_at'] = Clock::now();
            $contact['message']['body'] = preg_replace(Messages::MARKER, 'the blue folder in the office', $contact['message']['body']);
        }
        $this->engine->contacts()->save($contact);
        return $contact;
    }

    /** A complete, armed install with all three roles. */
    public static function armed(string $start = '2026-10-07 13:00'): self
    {
        $w = new self($start);
        $w->contact('nok', 'Kim Rivers', self::NOK);
        $w->contact('technical', 'Tao Chen', self::TECH);
        $w->contact('legal', 'Lee & Co', self::LEGAL);
        $w->engine->tick();
        $errors = $w->engine->arm();
        if ($errors) {
            throw new \RuntimeException('Could not arm: ' . implode('; ', $errors));
        }
        return $w;
    }

    /** Link a Telegram chat, as Settings does. */
    public function withTelegram(): self
    {
        $config = $this->store->read('config');
        $config['telegram'] = ['bot_token' => '123456789:' . str_repeat('A', 35), 'bot_name' => 'sam_switch_bot', 'chat_id' => '4242', 'linked_at' => Clock::now()];
        $this->store->write('config', $config);
        return $this;
    }

    /** Run cron once an hour for $hours hours. */
    public function hours(int $hours): void
    {
        for ($i = 0; $i < $hours; $i++) {
            Clock::advance(H);
            $this->engine->tick();
        }
    }

    /** Let time pass with cron stopped. */
    public function outage(int $hours): void
    {
        Clock::advance($hours * H);
    }

    /** Run hourly until the given local time. */
    public function until(string $local): void
    {
        $target = (new \DateTimeImmutable($local, new \DateTimeZone('Australia/Sydney')))->getTimestamp();
        while (Clock::now() < $target) {
            Clock::advance(min(H, $target - Clock::now()));
            $this->engine->tick();
        }
    }

    /** @return array<int, array> messages sent to this address, oldest first */
    public function to(string $address, ?string $subjectContains = null): array
    {
        return array_values(array_filter($this->mail->sent, static fn (array $m): bool =>
            in_array($address, $m['to'], true) && ($subjectContains === null || str_contains($m['subject'], $subjectContains))));
    }

    /** The newest token in a link to $page sent to $address. */
    public function token(string $address, string $page): ?string
    {
        foreach (array_reverse($this->to($address)) as $message) {
            if (preg_match('~/' . preg_quote($page, '~') . '\?t=([A-Za-z0-9_-]+)~', $message['text'], $m)) {
                return $m[1];
            }
        }
        return null;
    }

    public function state(): array
    {
        return $this->engine->state();
    }

    public function local(int $ts): string
    {
        return \DPS\when($ts, 'Australia/Sydney', 'D j M H:i');
    }
}

// ---------------------------------------------------------------------------
// A very small test runner
// ---------------------------------------------------------------------------

$tests = [];
$failures = 0;
$assertions = 0;

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[$name] = $fn;
}

function check(bool $condition, string $what): void
{
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new \RuntimeException('Expected: ' . $what);
    }
}

function same(mixed $expected, mixed $actual, string $what): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new \RuntimeException($what . "\n      expected: " . var_export($expected, true) . "\n      actual:   " . var_export($actual, true));
    }
}

// ---------------------------------------------------------------------------
// The weekly slot
// ---------------------------------------------------------------------------

test('the weekly slot is the next Monday 9am in the owner\'s timezone', function (): void {
    $tz = new \DateTimeZone('Australia/Sydney');
    $wed = (new \DateTimeImmutable('2026-10-07 13:00', $tz))->getTimestamp();
    $slot = Schedule::nextSlot($wed, 'Australia/Sydney', 1, 9);
    same('2026-10-12 09:00', (new \DateTimeImmutable('@' . $slot))->setTimezone($tz)->format('Y-m-d H:i'), 'next slot from a Wednesday');

    $monday9 = (new \DateTimeImmutable('2026-10-12 09:00', $tz))->getTimestamp();
    $next = Schedule::nextSlot($monday9, 'Australia/Sydney', 1, 9);
    same('2026-10-19 09:00', (new \DateTimeImmutable('@' . $next))->setTimezone($tz)->format('Y-m-d H:i'), 'a slot is strictly after the given time');

    $before = (new \DateTimeImmutable('2026-10-12 08:59', $tz))->getTimestamp();
    same($monday9, Schedule::nextSlot($before, 'Australia/Sydney', 1, 9), 'one minute before the slot');
});

test('the slot stays at 9am local across a daylight-saving change', function (): void {
    $tz = new \DateTimeZone('Australia/Sydney');
    // Daylight saving ends in Sydney on Sunday 4 April 2027.
    $before = (new \DateTimeImmutable('2027-03-29 09:00', $tz))->getTimestamp();
    $next = Schedule::nextSlot($before, 'Australia/Sydney', 1, 9);
    same('2027-04-05 09:00', (new \DateTimeImmutable('@' . $next))->setTimezone($tz)->format('Y-m-d H:i'), 'slot after the change');
    same(7 * D + H, $next - $before, 'that week is an hour longer');
});

// ---------------------------------------------------------------------------
// The ordinary week
// ---------------------------------------------------------------------------

test('an armed switch emails the owner at the weekly slot, and a check-in resets it', function (): void {
    $w = World::armed();
    same(1, count($w->to(World::OWNER, 'is armed')), 'owner is told the switch is armed');

    $w->until('2026-10-12 08:00');
    same(0, count($w->to(World::OWNER, 'Check in')), 'nothing before the slot');

    $w->until('2026-10-12 09:00');
    $emails = $w->to(World::OWNER, 'Check in');
    same(1, count($emails), 'one check-in email at the slot');
    same([World::OWNER], $emails[0]['to'], 'the weekly email goes to the main address only');
    check(str_contains($emails[0]['text'], 'Your switch is armed.'), 'the weekly email doubles as the "still armed" signal');

    $token = $w->token(World::OWNER, 'checkin.php');
    check($token !== null, 'the email carries a check-in link');
    $result = $w->engine->checkInByToken($token);
    check($result !== null, 'the link checks in');
    same(null, $w->state()['cycle'], 'the cycle is closed');
    same('Mon 19 Oct 09:00', $w->local($w->state()['next_due_at']), 'the next email is a week later');

    $w->until('2026-10-19 09:00');
    same(2, count($w->to(World::OWNER, 'Check in')), 'the following week\'s email arrives');
    same(0, count($w->to(World::NOK)), 'the next of kin has heard nothing');
    same(0, count($w->to(World::TECH)), 'the technical contact has heard nothing');
});

test('opening a check-in link is not a check-in, and a link works once', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $token = $w->token(World::OWNER, 'checkin.php');

    // What an email scanner does: it looks.
    for ($i = 0; $i < 5; $i++) {
        check($w->engine->checkinTokenIsLive($token), 'the link is live');
    }
    check($w->state()['cycle'] !== null, 'looking at the link changed nothing');
    same('checkin', $w->state()['cycle']['stage'], 'still waiting for the button');

    check($w->engine->checkInByToken($token) !== null, 'the button checks in');
    same(null, $w->engine->checkInByToken($token), 'the same link cannot be used twice');
    check(!$w->engine->checkinTokenIsLive($token), 'and no longer shows as live');
    same(null, $w->engine->checkInByToken('not-a-real-token'), 'a made-up token does nothing');
});

test('tokens are stored only as hashes', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $token = $w->token(World::OWNER, 'checkin.php');
    $raw = file_get_contents($w->store->path('state'));
    check(!str_contains($raw, $token), 'the state file does not hold the usable link');
    check(str_contains($raw, Tokens::hash($token)), 'it holds the hash');
});

test('a check-in link expires after 14 days', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $token = $w->token(World::OWNER, 'checkin.php');
    $w->outage(14 * 24 + 1);
    check(!$w->engine->checkinTokenIsLive($token), 'expired');
    same(null, $w->engine->checkInByToken($token), 'and cannot check in');
});

// ---------------------------------------------------------------------------
// The escalation
// ---------------------------------------------------------------------------

test('silence: reminders at 24h and 48h, next of kin at 72h, everyone else at 144h', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $t0 = Clock::now();

    $w->hours(23);
    same(0, count($w->to(World::OWNER, 'Reminder')), 'no reminder before 24h');
    $w->hours(1);
    $r1 = $w->to(World::OWNER, 'Reminder: check in');
    same(1, count($r1), 'first reminder at 24h');
    same($t0 + 24 * H, $r1[0]['at'], 'exactly 24h after the check-in email');
    same([World::OWNER, World::BACKUP], $r1[0]['to'], 'reminders go to both addresses');

    $w->hours(24);
    $r2 = $w->to(World::OWNER, 'Last reminder');
    same(1, count($r2), 'second reminder at 48h');
    same($t0 + 48 * H, $r2[0]['at'], 'exactly 48h');

    $w->hours(23);
    same(0, count($w->to(World::NOK)), 'the next of kin is not asked before 72h');
    $w->hours(1);
    $ask = $w->to(World::NOK);
    same(1, count($ask), 'the next of kin is asked at 72h');
    same($t0 + 72 * H, $ask[0]['at'], 'exactly 72h');
    same('Is Sam Rivers all right?', $ask[0]['subject'], 'the verify subject');
    check(str_contains($ask[0]['text'], 'respond.php?t='), 'with answer links');
    check(str_contains($ask[0]['text'], 'a=fine') && str_contains($ask[0]['text'], 'a=wrong'), 'both answers offered');
    same('Sam Rivers via Death Plan Switch', $ask[0]['from'], 'the sender name is recognisable');
    same(1, count($w->to(World::OWNER, 'asked your next of kin')), 'the owner is told, with another link');
    same(0, count($w->to(World::TECH)) + count($w->to(World::LEGAL)), 'nobody else has been contacted');

    $w->hours(48);
    same(2, count($w->to(World::NOK)), 'the next of kin is reminded at 120h');
    $w->hours(23);
    same(0, count($w->to(World::TECH)), 'nothing to the others before 144h');
    $w->hours(1);

    same($t0 + 144 * H, $w->state()['cycle']['fired_at'], 'the switch fires exactly 6 days after the check-in email');
    $tech = $w->to(World::TECH);
    same(1, count($tech), 'the technical contact gets one message');
    check(str_contains($tech[0]['text'], 'the blue folder in the office'), 'carrying the owner\'s pointer');
    check(str_contains($tech[0]['text'], 'Kim Rivers, ' . World::NOK), 'and the next of kin\'s details');
    same(1, count($w->to(World::LEGAL)), 'the legal contact gets one message');
    same(3, count($w->to(World::NOK)), 'the next of kin gets their own message too');
    check($w->to(World::NOK)[2]['at'] <= $tech[0]['at'], 'next of kin first');
    same(1, count($w->to(World::OWNER, 'has fired')), 'the owner is told it fired, with a way to cancel');

    // 13 days at most since the last check-in (arming on the 7th counted as one).
    $lastCheckin = $w->state()['last_checkin_at'];
    check($w->state()['cycle']['fired_at'] - $lastCheckin <= 13 * D, 'at most 13 days after the last check-in');

    $w->hours(72);
    same(1, count($w->to(World::TECH)), 'nobody is sent their message twice');
    same(1, count($w->to(World::LEGAL)), 'nobody is sent their message twice');
});

test('13 days is the worst case: a check-in at the slot, then silence', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->engine->checkInByToken($w->token(World::OWNER, 'checkin.php'));
    $checkin = Clock::now();
    $w->hours(13 * 24 - 1);
    same(0, count($w->to(World::TECH)), 'not before 13 days');
    $w->hours(1);
    same(1, count($w->to(World::TECH)), 'at 13 days exactly');
    same(13 * D, $w->state()['cycle']['fired_at'] - $checkin, '13 days');
});

test('checking in during the reminders: nobody else ever hears', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(50);
    $result = $w->engine->checkInByToken($w->token(World::OWNER, 'checkin.php'));
    same('checkin', $result['stage'], 'caught at the reminder stage');
    same([], $result['told'], 'nobody to tell');
    $w->hours(100);
    same(0, count($w->to(World::NOK)) + count($w->to(World::TECH)) + count($w->to(World::LEGAL)), 'no contact received anything');
});

test('an older reminder link still works', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $first = $w->token(World::OWNER, 'checkin.php');
    $w->hours(49);
    check($w->token(World::OWNER, 'checkin.php') !== $first, 'each email has its own link');
    check($w->engine->checkInByToken($first) !== null, 'the first email\'s link still checks in');
});

test('checking in after the next of kin was asked: they are told it was a false alarm', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(80);
    same(1, count($w->to(World::NOK)), 'next of kin asked');
    $nokToken = $w->token(World::NOK, 'respond.php');

    $result = $w->engine->checkInByToken($w->token(World::OWNER, 'checkin.php'));
    same('verify', $result['stage'], 'caught at the verify stage');
    same(['Kim Rivers'], $result['told'], 'the next of kin is told');
    same(1, count($w->to(World::NOK, 'False alarm')), 'by email');
    same(World::OWNER, $w->to(World::NOK, 'False alarm')[0]['reply'], 'replies to the false alarm reach the owner');
    check(!$w->engine->verifyTokenIsLive($nokToken), 'their answer links stop working');
    same(null, $w->engine->nokAnswer($nokToken, 'wrong'), 'so a late press does nothing');

    $w->hours(200);
    same(0, count($w->to(World::TECH)) + count($w->to(World::LEGAL)), 'the others never hear');
});

test('checking in after the switch fired: everyone contacted is told it was a false alarm', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(150);
    same('fired', $w->state()['cycle']['stage'], 'fired');

    $result = $w->engine->checkInByToken($w->token(World::OWNER, 'checkin.php'));
    same('fired', $result['stage'], 'caught after firing');
    sort($result['told']);
    same(['Kim Rivers', 'Lee & Co', 'Tao Chen'], $result['told'], 'all three are told');
    foreach ([World::NOK, World::TECH, World::LEGAL] as $address) {
        same(1, count($w->to($address, 'False alarm')), "false alarm sent to {$address}");
    }
    check($w->state()['after_fire_at'] !== null, 'the dashboard will remind the owner to change what was revealed');
    check($w->state()['armed'], 'the switch stays armed');

    $w->until('2026-10-26 09:00');
    check(count($w->to(World::OWNER, 'Check in')) >= 2, 'and the weekly emails carry on');
});

test('the owner can also check in from the dashboard', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(80);
    $result = $w->engine->checkIn('the dashboard');
    same(['Kim Rivers'], $result['told'], 'same false-alarm handling');
    same(null, $w->state()['cycle'], 'cycle closed');
});

// ---------------------------------------------------------------------------
// The next of kin's answer
// ---------------------------------------------------------------------------

test('next of kin answers "All fine": nobody else is contacted', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(75);
    $token = $w->token(World::NOK, 'respond.php');
    check($w->engine->verifyTokenIsLive($token), 'the answer link is live');
    same('fine', $w->engine->nokAnswer($token, 'fine'), 'answer recorded');
    same(null, $w->engine->nokAnswer($token, 'wrong'), 'the link is single use');
    same(null, $w->state()['cycle'], 'the cycle ends');
    same(1, count($w->to(World::OWNER, 'confirmed you are all right')), 'the owner is told who vouched for them');

    $w->until('2026-10-19 08:00');
    same(0, count($w->to(World::TECH)) + count($w->to(World::LEGAL)), 'nobody else heard anything');
    $w->until('2026-10-19 09:00');
    same(2, count($w->to(World::OWNER, 'Check in with')), 'the next week starts normally');
});

test('next of kin answers "Something\'s wrong": the others are told at once', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(75);
    $at = Clock::now();
    same('wrong', $w->engine->nokAnswer($w->token(World::NOK, 'respond.php'), 'wrong'), 'answer recorded');
    same('fired', $w->state()['cycle']['stage'], 'fired immediately');
    same('nok_wrong', $w->state()['cycle']['fired_reason'], 'for the right reason');
    same($at, $w->to(World::TECH)[0]['at'], 'the technical contact is told in the same moment');
    same(1, count($w->to(World::LEGAL)), 'and the legal contact');
    same(2, count($w->to(World::NOK)), 'the next of kin gets their instructions');
});

test('a nonsense answer is ignored', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(75);
    same(null, $w->engine->nokAnswer($w->token(World::NOK, 'respond.php'), 'maybe'), 'ignored');
    same('verify', $w->state()['cycle']['stage'], 'still waiting');
});

// ---------------------------------------------------------------------------
// When things break
// ---------------------------------------------------------------------------

test('cron stopped across the slot: the email goes late and the full window still applies', function (): void {
    $w = World::armed();
    $w->until('2026-10-11 09:00');
    $w->outage(5 * 24); // cron dead from Sunday to Friday
    $w->engine->tick();
    $start = Clock::now();
    same(1, count($w->to(World::OWNER, 'Check in')), 'the check-in email goes out on the first run back');
    same(0, count($w->to(World::OWNER, 'Reminder')), 'with no reminder bunched behind it');

    $w->hours(71);
    same(0, count($w->to(World::NOK)), 'the owner still gets a full 72 hours');
    $w->hours(1);
    same(1, count($w->to(World::NOK)), 'then the next of kin is asked');
    same($start + 72 * H, $w->to(World::NOK)[0]['at'], '72 hours after the late email');
});

test('cron stopped mid-cycle: overdue steps go one at a time, at least 12 hours apart', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(2);
    $w->outage(7 * 24); // a week of silence from the server itself: every step is now overdue

    $w->engine->tick();
    $back = Clock::now();
    same(1, count($w->to(World::OWNER, 'Reminder: check in')), 'first run back: reminder 1 only');
    same(0, count($w->to(World::OWNER, 'Last reminder')), 'not reminder 2');
    same(0, count($w->to(World::NOK)), 'and not the next of kin');

    $w->hours(11);
    same(0, count($w->to(World::OWNER, 'Last reminder')), 'nothing more inside 12 hours');
    $w->hours(1);
    same(1, count($w->to(World::OWNER, 'Last reminder')), 'reminder 2 after 12 hours');
    $w->hours(11);
    same(0, count($w->to(World::NOK)), 'the next of kin waits another 12 hours');
    $w->hours(1);
    same(1, count($w->to(World::NOK)), 'then is asked');
    same($back + 24 * H, $w->to(World::NOK)[0]['at'], '24 hours after cron came back');

    $w->hours(71);
    same(0, count($w->to(World::TECH)), 'and still gets their full 72 hours');
    $w->hours(1);
    same(1, count($w->to(World::TECH)), 'before anyone else is told');
});

test('mail server down at the slot: nothing escalates until email works', function (): void {
    $w = World::armed();
    $w->mail->failWhen = static fn (): bool => true;
    $w->until('2026-10-12 09:00');
    $w->hours(200);
    same(null, $w->state()['cycle'], 'no cycle started, because the owner could not be warned');
    check(!$w->engine->health()['ok'], 'the health check reports the fault');
    check(in_array('mail', $w->engine->health()['problems'], true), 'as a mail problem');

    $w->mail->failWhen = null;
    $w->hours(1);
    same(1, count($w->to(World::OWNER, 'Check in')), 'the check-in email goes as soon as mail works');
    check($w->engine->health()['ok'], 'and the health check clears');
});

test('mail server down mid-cycle: reminders wait, they are not skipped', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->mail->failWhen = static fn (): bool => true;
    $w->hours(160);
    same('checkin', $w->state()['cycle']['stage'], 'the cycle has not moved on');
    same(0, count($w->to(World::NOK)), 'the next of kin was not asked');
    $w->mail->failWhen = null;
    $w->hours(1);
    same(1, count($w->to(World::OWNER, 'Reminder: check in')), 'reminder 1 goes first');
});

test('next of kin\'s address is rejected: the 72-hour wait still runs and the others are told', function (): void {
    $w = World::armed();
    $w->mail->failWhen = static fn (array $to): bool => in_array(World::NOK, $to, true);
    $w->until('2026-10-12 09:00');
    $w->hours(72);
    same('verify', $w->state()['cycle']['stage'], 'the verify stage starts, because the owner could be told');
    same(false, $w->state()['cycle']['verify']['reached'], 'the next of kin was not reached');
    $w->hours(71);
    same(0, count($w->to(World::TECH)), 'the others are not told early');
    $w->hours(1);
    same(1, count($w->to(World::TECH)), 'but are told on time');

    // An owner check-in must not send a false alarm to someone who never got the alarm.
    $w2 = World::armed();
    $w2->mail->failWhen = static fn (array $to): bool => in_array(World::NOK, $to, true);
    $w2->until('2026-10-12 09:00');
    $w2->hours(80);
    same([], $w2->engine->checkIn('the dashboard')['told'], 'no false alarm to an unreached next of kin');
});

test('a contact who cannot be emailed when the switch fires is retried', function (): void {
    $w = World::armed();
    $w->mail->failWhen = static fn (array $to): bool => in_array(World::TECH, $to, true);
    $w->until('2026-10-12 09:00');
    $w->hours(144);
    same(0, count($w->to(World::TECH)), 'not delivered yet');
    same(1, count($w->to(World::LEGAL)), 'the others were not held up');
    $w->hours(5);
    $w->mail->failWhen = null;
    $w->hours(1);
    same(1, count($w->to(World::TECH)), 'delivered once mail to them works');
    $w->hours(10);
    same(1, count($w->to(World::TECH)), 'and only once');
    same(1, count($w->to(World::LEGAL)), 'the others were not sent a second copy');
});

test('a false-alarm notice that cannot be sent is retried', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(150);
    $w->mail->failWhen = static fn (array $to): bool => in_array(World::LEGAL, $to, true);
    $told = $w->engine->checkIn('the dashboard')['told'];
    check(!in_array('Lee & Co', $told, true), 'the legal contact could not be told straight away');
    $w->mail->failWhen = null;
    $w->hours(1);
    same(1, count($w->to(World::LEGAL, 'False alarm')), 'they are told on the next run');
    same([], $w->state()['all_clear_queue'], 'and the queue is empty');
});

test('the health check reports a stopped scheduler', function (): void {
    $w = World::armed();
    $w->hours(3);
    check($w->engine->health()['ok'], 'healthy while cron runs');
    $w->outage(25);
    $health = $w->engine->health();
    check(!$health['ok'], 'unhealthy after a day without a run');
    same(['scheduler'], $health['problems'], 'and says why');
    same(['ok', 'armed', 'problems'], array_keys($health), 'nothing private is included');
});

// ---------------------------------------------------------------------------
// Pause
// ---------------------------------------------------------------------------

test('a pause stops the emails and ends by itself', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(30); // mid-cycle, one reminder sent
    $before = count($w->mail->sent);
    $result = $w->engine->pause(10);
    same(Clock::now() + 10 * D, $result['until'], 'paused for ten days');
    same(null, $w->state()['cycle'], 'the open cycle is closed: pausing is proof of life');

    $w->hours(10 * 24 - 1);
    same($before, count($w->mail->sent), 'no email at all while paused');
    $w->hours(1);
    same(null, $w->state()['paused_until'], 'the pause ended automatically');
    same(1, count($w->to(World::OWNER, 'active again')), 'and the owner was told');

    $w->until('2026-10-26 09:00');
    same(2, count($w->to(World::OWNER, 'Check in with')), 'check-ins resume at the next slot');
});

test('a pause cannot be longer than 28 days', function (): void {
    $w = World::armed();
    $result = $w->engine->pause(365);
    same(Clock::now() + 28 * D, $result['until'], 'clamped to 28 days');
    $w2 = new World();
    same(null, $w2->engine->pause(5), 'a switch that is not armed cannot be paused');
});

test('pausing after the next of kin was asked tells them it was a false alarm', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(80);
    same(['Kim Rivers'], $w->engine->pause(7)['told'], 'next of kin told');
});

// ---------------------------------------------------------------------------
// Arming
// ---------------------------------------------------------------------------

test('the switch will not arm until everything is in place', function (): void {
    $w = new World();
    $errors = $w->engine->arm();
    check(count($errors) >= 3, 'a bare install cannot be armed');
    check(!$w->state()['armed'], 'and is not');

    $w->engine->tick();
    $nok = $w->contact('nok', 'Kim Rivers', World::NOK, false);
    $tech = $w->contact('technical', 'Tao Chen', World::TECH, false);
    check(in_array('Your next of kin has confirmed', $w->engine->arm(), true), 'unconfirmed next of kin blocks arming');

    // Confirm both through the real emailed links.
    foreach ([[$nok, World::NOK], [$tech, World::TECH]] as [$contact, $address]) {
        check($w->engine->sendConfirmation($contact['id']), 'confirmation email sent');
        $answer = $w->engine->contactAnswer($w->token($address, 'confirm.php'), true);
        check($answer !== null && $answer['accepted'], 'contact confirmed');
    }
    same(2, count($w->to(World::OWNER, 'has confirmed as your')), 'the owner hears about each confirmation');

    $errors = $w->engine->arm();
    check(in_array('Every message says where things are', $errors, true), 'starter text in a message blocks arming');
    check(in_array('Each contact has been given what they need', $errors, true), 'so do unticked contacts');

    foreach ($w->engine->contacts()->all() as $contact) {
        $contact['message']['body'] = preg_replace(Messages::MARKER, 'the safe', $contact['message']['body']);
        $w->engine->contacts()->save($contact);
    }
    $w->engine->recordReview(array_column($w->engine->contacts()->all(), 'id'));
    same([], $w->engine->arm(), 'now it arms');
    check($w->state()['armed'], 'armed');
});

test('the switch will not arm while the data folder is readable from the web', function (): void {
    $w = new World();
    $w->probe = 'exposed';
    $w->contact('nok', 'Kim Rivers', World::NOK);
    $w->contact('technical', 'Tao Chen', World::TECH);
    $w->engine->tick();
    same(['The data folder cannot be read from the web'], $w->engine->arm(), 'refused, for that reason alone');
    same(1, count($w->to(World::OWNER, 'data folder can be read')), 'and the owner is warned by email');
    $w->hours(48);
    same(1, count($w->to(World::OWNER, 'data folder can be read')), 'once, not every day');
});

test('the switch will not arm while the scheduler is not running', function (): void {
    $w = new World();
    $w->contact('nok', 'Kim Rivers', World::NOK);
    $w->contact('technical', 'Tao Chen', World::TECH);
    same(['The scheduler is running', 'The data folder cannot be read from the web'], $w->engine->arm(), 'no run recorded yet');
    $w->engine->tick();
    $w->outage(25);
    same(['The scheduler is running'], $w->engine->arm(), 'a stale run is not good enough');
});

// ---------------------------------------------------------------------------
// Contacts
// ---------------------------------------------------------------------------

test('only confirmed contacts are ever written to', function (): void {
    $w = World::armed();
    $w->contact('other', 'Unconfirmed Person', 'other@example.test', false);
    $w->until('2026-10-12 09:00');
    $w->hours(150);
    same(0, count($w->to('other@example.test')), 'an unconfirmed contact gets nothing when the switch fires');
    same(1, count($w->to(World::TECH)), 'confirmed contacts do');
});

test('a contact who declines is not written to again', function (): void {
    $w = new World();
    $contact = $w->contact('legal', 'Lee & Co', World::LEGAL, false);
    $w->engine->sendConfirmation($contact['id']);
    $token = $w->token(World::LEGAL, 'confirm.php');
    $answer = $w->engine->contactAnswer($token, false);
    same(false, $answer['accepted'], 'declined');
    same('declined', $w->engine->contacts()->find($contact['id'])['status'], 'recorded');
    same(null, $w->engine->contactAnswer($token, true), 'the link is single use');
    same(1, count($w->to(World::OWNER, 'has declined')), 'the owner is told');
});

test('a required contact declining disarms the switch, loudly', function (): void {
    $w = World::armed();
    $nok = $w->engine->contacts()->nok();
    $w->outage(366 * 24);
    $w->engine->tick(); // the yearly reconfirmation request goes out
    $token = $w->token(World::NOK, 'confirm.php');
    check($token !== null, 'next of kin was asked to reconfirm after a year');
    $w->engine->contactAnswer($token, false);
    check(!$w->state()['armed'], 'the switch is disarmed: it cannot verify without a next of kin');
    $notice = $w->to(World::OWNER, 'no longer armed');
    same(1, count($notice), 'the owner is told');
    same([World::OWNER, World::BACKUP], $notice[0]['to'], 'at both addresses');
    same('declined', $w->engine->contacts()->find($nok['id'])['status'], 'recorded');
});

test('changing a confirmed contact\'s email keeps the old address until the new one confirms', function (): void {
    $w = World::armed();
    $tech = $w->engine->contacts()->confirmedOfKind('technical')[0];
    $tech['pending_email'] = 'new-tech@example.test';
    $w->engine->contacts()->save($tech);
    check($w->engine->sendConfirmation($tech['id']), 'confirmation goes out');
    same(1, count($w->to('new-tech@example.test')), 'to the new address');
    same(World::TECH, $w->engine->contacts()->find($tech['id'])['email'], 'the old address is still the live one');
    check($w->engine->requiredContactsConfirmed(), 'so the switch still has its technical contact');

    $w->engine->contactAnswer($w->token('new-tech@example.test', 'confirm.php'), true);
    $after = $w->engine->contacts()->find($tech['id']);
    same('new-tech@example.test', $after['email'], 'the new address takes over once confirmed');
    same(null, $after['pending_email'], 'nothing pending');
});

test('contacts are asked to reconfirm once a year, with one nudge', function (): void {
    $w = World::armed();
    $w->engine->checkIn('test');
    $w->engine->pause(28); // keep the cycle out of the way; we only care about the calendar here
    $w->outage(364 * 24);
    $w->engine->tick();
    same(0, count($w->to(World::TECH, 'Still happy')), 'not before a year');
    $w->outage(2 * 24);
    $w->engine->tick();
    same(1, count($w->to(World::TECH, 'Still happy')), 'asked after a year');
    $w->outage(24);
    $w->engine->tick();
    same(1, count($w->to(World::TECH, 'Still happy')), 'not asked again the next day');
    $w->outage(14 * 24);
    $w->engine->tick();
    same(2, count($w->to(World::TECH, 'Still happy')), 'nudged once after two weeks');
    $w->outage(20 * 24);
    $w->engine->tick();
    same(2, count($w->to(World::TECH, 'Still happy')), 'and never again');
    check(in_array('contact', array_column($w->engine->warnings(), 'key'), true), 'the owner sees a warning instead');

    $w->engine->contactAnswer($w->token(World::TECH, 'confirm.php'), true);
    $tech = $w->engine->contacts()->confirmedOfKind('technical')[0];
    same(null, $tech['reconfirm_asked_at'], 'reconfirming clears the request');
});

test('the owner gets a yearly prompt to review what each contact holds', function (): void {
    $w = World::armed();
    $w->outage(366 * 24);
    $w->engine->tick();
    same(1, count($w->to(World::OWNER, 'yearly review')), 'prompted after a year');
    $w->outage(24);
    $w->engine->tick();
    same(1, count($w->to(World::OWNER, 'yearly review')), 'once');
    check(in_array('review', array_column($w->engine->warnings(), 'key'), true), 'and it stays on the dashboard');
    $w->engine->recordReview(array_column($w->engine->contacts()->all(), 'id'));
    check(!in_array('review', array_column($w->engine->warnings(), 'key'), true), 'until the review is done');
});

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------

test('placeholders are filled in for each recipient', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(150);
    $legal = $w->to(World::LEGAL)[0];
    check(str_contains($legal['text'], 'Next of kin: Kim Rivers, ' . World::NOK), 'next of kin details');
    check(str_contains($legal['text'], 'Technical contact: Tao Chen, ' . World::TECH), 'technical details');
    check(!preg_match('/\{[a-z_]+\}/', $legal['text'] . $legal['subject']), 'no unfilled placeholder is left');
    $nok = $w->to(World::NOK)[2];
    check(str_contains($nok['text'], 'Technical 2IC: Tao Chen'), 'the next of kin is told who the others are');
    check(!str_contains($nok['text'], 'Next of kin: Kim Rivers'), 'but is not listed to themselves');
    // Armed (the last proof of life) on Wed 7 Oct at 1pm; asked on Thu 15 Oct at 9am.
    check(str_contains(str_replace("\n", ' ', $w->to(World::NOK)[0]['text']), 'for 7 days'), 'whole days since the last check-in');
});

test('starter text is detected, and obvious logins are flagged', function (): void {
    check(Messages::hasMarker(Messages::defaults('technical')['body']), 'the technical starter text has a marker');
    check(Messages::hasMarker(Messages::defaults('nok')['body']), 'so does the next of kin\'s');
    check(!Messages::hasMarker(Messages::defaults('legal')['body']), 'the legal one needs no pointer');
    check(!Messages::hasMarker('The handover document is in the shared folder [Tecky handover].'), 'ordinary square brackets are fine');
    check(Messages::looksLikeLogin('The server password is hunter2'), 'a password in a message is flagged');
    check(Messages::looksLikeLogin('login: admin'), 'so is a login');
    check(!Messages::looksLikeLogin('Sultan holds the password. The document is at https://example.test/doc'), 'saying who holds the password is fine');
});

test('emails are plain: text and HTML say the same thing, and user text is escaped', function (): void {
    $email = (new Email('Subject'))->prose("Line one\nLine two\n\n<script>alert(1)</script> see https://example.test/a?b=1&c=2.")->link('Open', 'https://example.test/x?t=abc');
    $html = $email->html();
    check(!str_contains($html, '<script>'), 'HTML is escaped');
    check(str_contains($html, '<a href="https://example.test/a?b=1&amp;c=2"'), 'bare links become links, without the trailing full stop');
    check(str_contains($email->text(), "Open:\nhttps://example.test/x?t=abc"), 'the text part carries the full link');
    check(!preg_match('/<img|<link|url\(/i', $html), 'no images or remote content');
});

// ---------------------------------------------------------------------------
// Storage
// ---------------------------------------------------------------------------

test('a damaged file falls back to the last good copy and says so', function (): void {
    $w = World::armed();
    $w->engine->tick();
    $good = $w->state();
    file_put_contents($w->store->path('state'), '{"armed": tr'); // a half-written file
    $fresh = new Store($w->dir);
    $read = $fresh->read('state');
    same(true, $read['armed'], 'the switch is still armed, read from the backup');
    same(['state'], $fresh->recoveredFiles(), 'and the recovery is reported');
    check($good['armed_at'] === $read['armed_at'], 'with the right data');

    file_put_contents($w->store->path('state') . '.bak', 'garbage');
    $threw = false;
    try {
        (new Store($w->dir))->read('state');
    } catch (StoreException) {
        $threw = true;
    }
    check($threw, 'with no good copy at all, it fails loudly instead of acting like a fresh install');
});

test('a deleted file is restored from backup, not treated as a fresh install', function (): void {
    $w = World::armed();
    $w->store->write('config', $w->engine->config() + ['touched' => true]); // ensure a .bak exists
    unlink($w->store->path('config'));
    $config = (new Store($w->dir))->read('config');
    same('Sam Rivers', $config['owner_name'], 'config came back from the backup');
});

test('a failed write leaves the original untouched', function (): void {
    $w = new World();
    $w->store->write('thing', ['value' => 1]);
    $threw = false;
    try {
        $w->store->write('thing', ['value' => "\xB1\x31"]); // invalid UTF-8 cannot be encoded
    } catch (\Throwable) {
        $threw = true;
    }
    check($threw, 'the write is refused');
    same(['value' => 1], $w->store->read('thing'), 'and the file is as it was');
    same([], glob($w->dir . '/*.tmp.*') ?: [], 'with no temporary file left behind');
});

test('simultaneous writers do not lose each other\'s changes', function (): void {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped: pcntl not available)\n";
        return;
    }
    $w = new World();
    $w->store->write('counter', ['n' => 0]);
    $dir = $w->dir;
    $children = [];
    for ($p = 0; $p < 8; $p++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            $store = new Store($dir);
            for ($i = 0; $i < 50; $i++) {
                $store->locked(function () use ($store): void {
                    $doc = $store->read('counter');
                    $doc['n']++;
                    $store->write('counter', $doc);
                });
            }
            // Leave without running the parent's destructors or shutdown handlers.
            posix_kill(posix_getpid(), SIGKILL);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
    }
    same(400, (new Store($dir))->read('counter')['n'], '8 processes x 50 increments');
});

test('the history is kept and stays small', function (): void {
    $w = new World();
    for ($i = 0; $i < 6000; $i++) {
        $w->store->log('test', str_repeat('x', 80) . $i);
    }
    check(filesize($w->dir . '/history.jsonl') < 450_000, 'the file is trimmed');
    same(str_repeat('x', 80) . '5999', $w->store->history(1)[0]['text'], 'and the newest entry is kept');
});

// ---------------------------------------------------------------------------
// Disarming
// ---------------------------------------------------------------------------

test('disarming stops everything and tells anyone already contacted', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(80);
    same(['Kim Rivers'], $w->engine->disarm(), 'the next of kin is told it was a false alarm');
    check(!$w->state()['armed'], 'disarmed');
    same(1, count($w->to(World::OWNER, 'no longer armed')), 'the owner gets a notice, in case it was not them');
    $count = count($w->mail->sent);
    $w->hours(24 * 21);
    same($count, count($w->mail->sent), 'a disarmed switch sends nothing');
});

// ---------------------------------------------------------------------------
// Telegram: a second way to reach the owner
// ---------------------------------------------------------------------------

test('without Telegram set up, nothing is sent there and nothing waits for it', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $w->hours(72);
    same([], $w->chat->sent, 'no Telegram messages');
    same(1, count($w->to(World::NOK, 'all right')), 'the next of kin is asked at 72 hours as before');
});

test('with Telegram, the check-in and reminders go there too, with a button that checks in', function (): void {
    $w = World::armed()->withTelegram();
    $w->until('2026-10-12 09:00');
    $checkin = $w->chat->titled('Check in with Death Plan Switch');
    same(1, count($checkin), 'the weekly check-in arrives on Telegram');
    same('4242', $checkin[0]['chat'], 'in the linked chat');
    same('Open the check-in page', $checkin[0]['button']['label'] ?? null, 'with a button');
    same(['email' => Clock::now(), 'telegram' => Clock::now()], $w->state()['cycle']['owner_reached'], 'both channels recorded');

    $w->hours(24);
    same(1, count($w->chat->titled('Reminder: check in')), 'the first reminder arrives on Telegram');

    check(preg_match('~checkin\.php\?t=([A-Za-z0-9_-]+)~', (string) $w->chat->sent[1]['button']['url'], $m) === 1, 'the button links to the check-in page');
    check($w->engine->checkInByToken($m[1]) !== null, 'pressing it checks in');
    same(null, $w->state()['cycle'], 'the cycle is closed');
});

test('Telegram down all week: the next of kin waits while the owner is chased', function (): void {
    $w = World::armed()->withTelegram();
    $w->chat->down = true;
    $w->until('2026-10-12 09:00');
    $w->hours(72);
    same(0, count($w->to(World::NOK)), 'the next of kin is not asked at 72 hours');
    same('checkin', $w->state()['cycle']['stage'], 'still waiting on the owner');
    $chases = $w->to(World::OWNER, 'Check in now');
    same(0, count($chases), 'email already reached the owner, so it is not chased there');
    check(in_array('channels', $w->engine->health()['problems'], true), 'the health check reports the hold');
    check(in_array('telegram', $w->engine->health()['problems'], true), 'and the Telegram failure');

    $w->hours(5);
    same(0, count($w->to(World::NOK)), 'still waiting');

    // Telegram comes back. The next chase gets through, and the owner gets 6 hours to see it.
    $w->chat->down = false;
    $w->hours(1);
    same(1, count($w->chat->titled('Check in now')), 'the owner is chased on Telegram');
    $w->hours(5);
    same(0, count($w->to(World::NOK)), 'the next of kin waits 6 hours after the chase');
    $w->hours(1);
    same(1, count($w->to(World::NOK, 'all right')), 'then the next of kin is asked');
    same(1, count($w->chat->titled('asked your next of kin')), 'and the owner is told on Telegram');
});

test('a chase on Telegram checks in like any other link', function (): void {
    $w = World::armed()->withTelegram();
    $w->chat->down = true;
    $w->until('2026-10-12 09:00');
    $w->hours(72);
    $w->chat->down = false;
    $w->hours(6);
    $chase = $w->chat->titled('Check in now');
    same(1, count($chase), 'chased');
    check(preg_match('~checkin\.php\?t=([A-Za-z0-9_-]+)~', $chase[0]['text'], $m) === 1, 'the chase carries a link');
    check($w->engine->checkInByToken($m[1]) !== null, 'it checks in');
    $w->hours(24 * 6);
    same(0, count($w->to(World::NOK)), 'the next of kin never hears');
});

test('Telegram dead for good: the next of kin is asked a week late, not never', function (): void {
    $w = World::armed()->withTelegram();
    $w->chat->down = true;
    $w->until('2026-10-12 09:00');
    $t0 = Clock::now();
    $w->hours(72 + 7 * 24 - 1);
    same(0, count($w->to(World::NOK)), 'held for a week past the usual 72 hours');
    $w->hours(1);
    $asked = $w->to(World::NOK, 'all right');
    same(1, count($asked), 'then asked anyway');
    same($t0 + 72 * H + 7 * D, $asked[0]['at'], 'exactly a week late');
    $w->hours(72);
    check(count($w->to(World::TECH)) > 0, 'and the switch can still fire');
});

test('email down but Telegram up: the next of kin waits for email too', function (): void {
    $w = World::armed()->withTelegram();
    $w->until('2026-10-12 09:00');
    $w->mail->failWhen = static fn (array $to): bool => in_array(World::OWNER, $to, true) || in_array(World::BACKUP, $to, true);
    $w->outage(1); // so the next run is the first with mail down
    // Wipe what email achieved so far, as if the weekly email had gone by Telegram only.
    $state = $w->state();
    $state['cycle']['owner_reached'] = ['telegram' => $state['cycle']['started_at']];
    $w->engine->saveState($state);

    $w->hours(72);
    same(0, count($w->to(World::NOK)), 'the next of kin is not asked');
    $w->mail->failWhen = null;
    $w->hours(6);
    same(1, count($w->to(World::OWNER, 'Check in now')), 'the owner is chased by email once it works');
    check(str_contains($w->to(World::OWNER, 'Check in now')[0]['text'], 'only by Telegram'), 'saying how they were reached');
});

test('a cycle started before Telegram was set up is not held up by it', function (): void {
    $w = World::armed();
    $w->until('2026-10-12 09:00');
    $state = $w->state();
    unset($state['cycle']['owner_reached']); // as written by the previous version
    $w->engine->saveState($state);
    $w->hours(72);
    same(1, count($w->to(World::NOK, 'all right')), 'asked on time');
});

test('Telegram messages carry the same words as the email', function (): void {
    $w = World::armed()->withTelegram();
    $w->until('2026-10-12 09:00');
    $email = $w->to(World::OWNER, 'Check in with')[0];
    $chat = $w->chat->titled('Check in with')[0];
    same($email['subject'] . "\n\n" . trim($email['text']), $chat['text'], 'same text');
});

// ---------------------------------------------------------------------------

$only = $argv[1] ?? null;
$started = microtime(true);
foreach ($tests as $name => $fn) {
    if ($only !== null && !str_contains($name, $only)) {
        continue;
    }
    try {
        $fn();
        echo "  ok    {$name}\n";
    } catch (\Throwable $e) {
        $failures++;
        echo "  FAIL  {$name}\n      " . $e->getMessage() . "\n      at " . basename($e->getFile()) . ':' . $e->getLine() . "\n";
    }
    Clock::set(null);
}
printf("\n%d tests, %d checks, %d failed (%.1fs)\n", count($tests), $assertions, $failures, microtime(true) - $started);
exit($failures > 0 ? 1 : 0);
