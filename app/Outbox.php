<?php
declare(strict_types=1);

namespace DPS;

/**
 * The one door every email, and every message to the owner on another
 * channel, leaves through.
 *
 * It never throws. It returns true or false, records the result where the
 * dashboard and the health check can see it, and writes a history line. The
 * engine relies on that return value: an escalation step only counts as
 * done when its email was accepted by the mail server.
 */
final class Outbox
{
    public const CHANNELS = ['email', 'telegram'];

    public function __construct(
        private Store $store,
        private Transport $transport,
        private ?Messenger $telegram = null,
        private ?Texter $texter = null,
        private ?Caller $caller = null,
    ) {
    }

    /** Is a text service set up? */
    public static function textReady(array $config): bool
    {
        return !empty($config['textbelt']['key']);
    }

    /** Is a call service set up? */
    public static function callReady(array $config): bool
    {
        return !empty($config['twilio']['account_sid']) && !empty($config['twilio']['auth_token']) && !empty($config['twilio']['from']);
    }

    /** Is Telegram set up and linked to the owner's chat? */
    public static function telegramReady(array $config): bool
    {
        return !empty($config['telegram']['bot_token']) && !empty($config['telegram']['chat_id']);
    }

    /**
     * The channels the owner can be reached on.
     *
     * @return string[]
     */
    public static function ownerChannels(array $config): array
    {
        return self::telegramReady($config) ? ['email', 'telegram'] : ['email'];
    }

    /**
     * Tell the owner something on every channel they have set up.
     *
     * @param string[] $addresses the owner's email addresses for this message
     * @param string[] $only      limit to these channels
     * @return string[] the channels that accepted it; empty if none did
     */
    public function toOwner(array $addresses, Email $email, array $only = self::CHANNELS): array
    {
        $config = $this->store->read('config');
        $reached = [];
        if (in_array('email', $only, true) && $this->send($addresses, $email)) {
            $reached[] = 'email';
        }
        if (in_array('telegram', $only, true) && self::telegramReady($config) && $this->telegram($email)) {
            $reached[] = 'telegram';
        }
        return $reached;
    }

    /**
     * Send an email's content to the owner's Telegram chat. Its first link
     * becomes a button.
     *
     * @param array|null $settings Override the saved Telegram settings (used while linking)
     */
    public function telegram(Email $email, ?array $settings = null): bool
    {
        $settings ??= (array) ($this->store->read('config')['telegram'] ?? []);
        if ($this->telegram === null) {
            return false;
        }
        try {
            $this->telegram->send($settings, $email->subject . "\n\n" . trim($email->text()), $email->firstLink());
            $this->recordTelegram(true, $email->subject, '');
            return true;
        } catch (\Throwable $e) {
            $this->recordTelegram(false, $email->subject, $e->getMessage());
            return false;
        }
    }

    /**
     * @param string|string[] $to
     * @param string $audience 'owner' or 'contact'; sets the From name and Reply-To
     * @param array|null $smtp Override the saved SMTP settings (used while testing new ones)
     */
    public function send(string|array $to, Email $email, string $audience = 'owner', ?array $smtp = null): bool
    {
        $to = array_values(array_unique(array_filter(array_map('trim', (array) $to))));
        if ($to === []) {
            return false;
        }
        $config = $this->store->read('config');
        $smtp ??= (array) ($config['smtp'] ?? []);
        $owner = trim((string) ($config['owner_name'] ?? ''));

        if ($audience === 'contact' && $owner !== '') {
            $fromName = $owner . ' via Death Plan Switch';
            $replyTo = (string) ($config['owner_email'] ?? '');
        } else {
            $fromName = 'Death Plan Switch';
            $replyTo = '';
        }

        try {
            $this->transport->send($smtp, $to, $fromName, $replyTo, $email);
            $this->record(true, $to, $email->subject, '');
            return true;
        } catch (\Throwable $e) {
            $this->record(false, $to, $email->subject, $e->getMessage());
            return false;
        }
    }

    private function record(bool $ok, array $to, string $subject, string $error): void
    {
        try {
            $this->store->locked(function () use ($ok, $to, $subject, $error): void {
                $state = $this->store->read('mail');
                if ($ok) {
                    $state['last_send_ok_at'] = Clock::now();
                    $state['last_send_error'] = null;
                } else {
                    $state['last_send_error'] = [
                        'at' => Clock::now(),
                        'to' => implode(', ', $to),
                        'subject' => $subject,
                        'error' => mb_substr($error, 0, 500),
                    ];
                }
                $this->store->write('mail', $state);
            });
        } catch (\Throwable) {
            // Recording is best effort; the caller still gets the true result.
        }
        $this->store->log(
            $ok ? 'mail.sent' : 'mail.failed',
            ($ok ? 'Sent' : 'Could not send') . ' "' . $subject . '" to ' . implode(', ', $to)
                . ($ok ? '' : '. ' . mb_substr($error, 0, 300))
        );
    }

    /**
     * Send a text. If $fallback is given and the text is refused, it is tried
     * instead: texts with links in them are the ones a service may refuse.
     *
     * @param string $who for the history, e.g. "Kim Rivers"
     */
    public function text(string $to, string $body, string $who, ?string $fallback = null, ?array $settings = null): bool
    {
        $settings ??= (array) ($this->store->read('config')['textbelt'] ?? []);
        if ($this->texter === null) {
            return false;
        }
        $error = '';
        foreach (array_filter([$body, $fallback]) as $attempt) {
            try {
                $left = $this->texter->text($settings, $to, $attempt);
                $this->recordPhone(true, "Texted {$who} at " . Phone::masked($to), '', $left);
                return true;
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
        $this->recordPhone(false, "Could not text {$who} at " . Phone::masked($to), $error);
        return false;
    }

    /**
     * Start a call. Whether it was answered is known later, from callStatus().
     *
     * @return string|null the call's id, or null if it could not be placed
     */
    public function call(string $to, string $script, string $who, ?array $settings = null): ?string
    {
        $settings ??= (array) ($this->store->read('config')['twilio'] ?? []);
        if ($this->caller === null) {
            return null;
        }
        try {
            $id = $this->caller->call($settings, $to, $script);
            $this->store->log('phone.called', "Calling {$who} at " . Phone::masked($to) . '.');
            return $id;
        } catch (\Throwable $e) {
            $this->recordPhone(false, "Could not call {$who} at " . Phone::masked($to), $e->getMessage());
            return null;
        }
    }

    /** @return string 'pending', 'answered' or 'missed' */
    public function callStatus(string $id, string $to, string $who): string
    {
        if ($this->caller === null) {
            return 'missed';
        }
        try {
            $status = $this->caller->status((array) ($this->store->read('config')['twilio'] ?? []), $id);
        } catch (\Throwable) {
            return 'pending'; // asked again on the next run
        }
        if ($status === 'answered') {
            $this->recordPhone(true, "The call to {$who} at " . Phone::masked($to) . ' was answered', '');
        } elseif ($status === 'missed') {
            $this->recordPhone(false, "The call to {$who} at " . Phone::masked($to) . ' was not answered', 'busy, no answer or failed');
        }
        return $status;
    }

    private function recordPhone(bool $ok, string $what, string $error, ?int $textsLeft = null): void
    {
        try {
            $this->store->locked(function () use ($ok, $what, $error, $textsLeft): void {
                $state = $this->store->read('mail');
                if ($textsLeft !== null) {
                    $state['texts_left'] = $textsLeft;
                }
                if ($ok) {
                    $state['phone_ok_at'] = Clock::now();
                    $state['phone_error'] = null;
                } else {
                    $state['phone_error'] = ['at' => Clock::now(), 'error' => $what . ': ' . mb_substr($error, 0, 400)];
                }
                $this->store->write('mail', $state);
            });
        } catch (\Throwable) {
            // Recording is best effort; the caller still gets the true result.
        }
        $this->store->log($ok ? 'phone.sent' : 'phone.failed', $what . ($ok ? '.' : '. ' . mb_substr($error, 0, 300)));
    }

    private function recordTelegram(bool $ok, string $subject, string $error): void
    {
        try {
            $this->store->locked(function () use ($ok, $subject, $error): void {
                $state = $this->store->read('mail');
                if ($ok) {
                    $state['telegram_ok_at'] = Clock::now();
                    $state['telegram_error'] = null;
                } else {
                    $state['telegram_error'] = [
                        'at' => Clock::now(),
                        'subject' => $subject,
                        'error' => mb_substr($error, 0, 500),
                    ];
                }
                $this->store->write('mail', $state);
            });
        } catch (\Throwable) {
            // Recording is best effort; the caller still gets the true result.
        }
        $this->store->log(
            $ok ? 'telegram.sent' : 'telegram.failed',
            ($ok ? 'Sent' : 'Could not send') . ' "' . $subject . '" by Telegram'
                . ($ok ? '' : '. ' . mb_substr($error, 0, 300))
        );
    }
}
