<?php
declare(strict_types=1);

namespace DPS;

/**
 * The one door every email leaves through.
 *
 * It never throws. It returns true or false, records the result where the
 * dashboard and the health check can see it, and writes a history line. The
 * engine relies on that return value: an escalation step only counts as
 * done when its email was accepted by the mail server.
 */
final class Outbox
{
    public function __construct(private Store $store, private Transport $transport)
    {
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
}
