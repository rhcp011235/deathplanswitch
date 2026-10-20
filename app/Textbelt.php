<?php
declare(strict_types=1);

namespace DPS;

/**
 * Sends texts through Textbelt. No phone number or carrier registration is
 * needed: an API key is bought once and used until it runs out.
 */
final class Textbelt implements Texter
{
    private const API = 'https://textbelt.com/';

    public function text(array $settings, string $to, string $body): ?int
    {
        $reply = self::request('text', ['phone' => $to, 'message' => $body, 'key' => (string) ($settings['key'] ?? '')]);
        if (empty($reply['success'])) {
            throw new \RuntimeException('Textbelt said: ' . (string) ($reply['error'] ?? 'the text was not sent'));
        }
        return isset($reply['quotaRemaining']) ? (int) $reply['quotaRemaining'] : null;
    }

    /**
     * How many texts the key has left. Checking costs nothing.
     *
     * @throws \RuntimeException
     */
    public static function quota(string $key): int
    {
        $reply = self::request('quota/' . rawurlencode($key));
        if (empty($reply['success'])) {
            throw new \RuntimeException('Textbelt said: ' . (string) ($reply['error'] ?? 'the key could not be checked'));
        }
        return (int) ($reply['quotaRemaining'] ?? 0);
    }

    private static function request(string $path, ?array $form = null): array
    {
        $reply = Http::request(self::API . $path, $form);
        if ($reply['status'] === 0) {
            throw new \RuntimeException('Textbelt could not be reached: ' . $reply['error'] . '.');
        }
        if ($reply['body'] === null) {
            throw new \RuntimeException('Textbelt sent back something unexpected (HTTP ' . $reply['status'] . ').');
        }
        return $reply['body'];
    }
}
