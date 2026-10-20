<?php
declare(strict_types=1);

namespace DPS;

/**
 * Phone numbers, and when it is decent to ring one.
 */
final class Phone
{
    /**
     * A number in the +E.164 form the phone services want, or null if it
     * cannot be one. Ten digits are taken as a US or Canadian number; any
     * other country needs its + and country code.
     */
    public static function e164(string $typed): ?string
    {
        $plus = str_starts_with(ltrim($typed), '+');
        $digits = preg_replace('/\D+/', '', $typed) ?? '';
        if ($plus) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 && $digits[0] !== '0' ? '+' . $digits : null;
        }
        if (strlen($digits) === 10 && $digits[0] !== '0' && $digits[0] !== '1') {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            return '+' . $digits;
        }
        return null;
    }

    /** May a phone ring now, by the owner's clock? Texts are sent at any hour; calls wait for the day. */
    public static function callableHour(int $now, string $tz): bool
    {
        $hour = (int) when($now, $tz, 'G');
        return $hour >= Schedule::CALL_FROM_HOUR && $hour < Schedule::CALL_UNTIL_HOUR;
    }

    /** How a number appears in the history: its last four digits only. */
    public static function masked(string $e164): string
    {
        return '…' . substr($e164, -4);
    }
}
