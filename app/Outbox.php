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

    public function __construct(private Store $store, private Transport $transport, private ?Messenger $telegram = null)
    {
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
