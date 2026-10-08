<?php
declare(strict_types=1);

namespace DPS;

/**
 * Random, single-use tokens for emailed links.
 *
 * Only the SHA-256 hash is stored, so someone who reads the data folder
 * cannot use the links inside it. Each token carries an expiry time.
 */
final class Tokens
{
    /** @return array{0: string, 1: array{hash: string, expires_at: int}} plain token, and the record to store */
    public static function issue(int $ttl): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        return [$plain, ['hash' => self::hash($plain), 'expires_at' => Clock::now() + $ttl]];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Does $plain match any unexpired record in $records? */
    public static function matches(string $plain, array $records): bool
    {
        if ($plain === '' || strlen($plain) > 200) {
            return false;
        }
        $hash = self::hash($plain);
        $now = Clock::now();
        $found = false;
        foreach ($records as $record) {
            // No early exit: compare against every record in constant time.
            if (
                is_array($record)
                && hash_equals((string) ($record['hash'] ?? ''), $hash)
                && (int) ($record['expires_at'] ?? 0) > $now
            ) {
                $found = true;
            }
        }
        return $found;
    }

    /** A short numeric code for confirming an email address by typing it in. */
    public static function code(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}
