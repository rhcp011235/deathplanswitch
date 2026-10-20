<?php
declare(strict_types=1);

namespace DPS;

/**
 * Places phone calls through the owner's Twilio account. A computer voice
 * reads the message twice. If voicemail answers, Twilio waits for the beep
 * so the whole message is left.
 *
 * The words are sent with each call, so nothing has to reach this server
 * from Twilio.
 */
final class Twilio implements Caller
{
    private const API = 'https://api.twilio.com/2010-04-01/Accounts/';

    public function call(array $settings, string $to, string $script): string
    {
        $say = '<Say>' . htmlspecialchars($script, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Say>';
        $twiml = '<Response><Pause length="1"/>' . $say . '<Pause length="2"/>' . $say . '</Response>';
        $result = self::api($settings, 'Calls.json', [
            'To' => $to,
            'From' => (string) ($settings['from'] ?? ''),
            'Twiml' => $twiml,
            'Timeout' => 40,
            'MachineDetection' => 'DetectMessageEnd',
        ]);
        $sid = (string) ($result['sid'] ?? '');
        if ($sid === '') {
            throw new \RuntimeException('Twilio did not say whether the call was placed.');
        }
        return $sid;
    }

    public function status(array $settings, string $id): string
    {
        if (!preg_match('/^CA[0-9a-f]{32}$/', $id)) {
            return 'missed';
        }
        $status = (string) (self::api($settings, 'Calls/' . $id . '.json')['status'] ?? '');
        return match ($status) {
            'completed' => 'answered',
            'busy', 'no-answer', 'failed', 'canceled' => 'missed',
            default => 'pending',
        };
    }

    public static function validSid(string $sid): bool
    {
        return (bool) preg_match('/^AC[0-9a-f]{32}$/', $sid);
    }

    /** Proves the account details work. Returns the account's name. */
    public static function check(array $settings): string
    {
        return (string) (self::api($settings, '')['friendly_name'] ?? '');
    }

    /** @throws \RuntimeException with Twilio's own reason */
    private static function api(array $settings, string $path, ?array $form = null): array
    {
        $sid = (string) ($settings['account_sid'] ?? '');
        if (!self::validSid($sid) || ($settings['auth_token'] ?? '') === '') {
            throw new \RuntimeException('The Twilio account SID or auth token is missing.');
        }
        $url = self::API . $sid . ($path === '' ? '.json' : '/' . $path);
        $reply = Http::request($url, $form, auth: $sid . ':' . $settings['auth_token']);
        if ($reply['status'] === 0) {
            throw new \RuntimeException('Twilio could not be reached: ' . $reply['error'] . '.');
        }
        if ($reply['status'] >= 400 || $reply['body'] === null) {
            $message = (string) ($reply['body']['message'] ?? 'unexpected reply, HTTP ' . $reply['status']);
            throw new \RuntimeException('Twilio said: ' . $message);
        }
        return $reply['body'];
    }
}
