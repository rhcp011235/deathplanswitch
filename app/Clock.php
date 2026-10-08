<?php
declare(strict_types=1);

namespace DPS;

/**
 * The only place the app reads the time, so the tests can run thirteen days
 * of escalation in a few milliseconds.
 */
final class Clock
{
    private static ?int $fixed = null;

    public static function now(): int
    {
        return self::$fixed ?? time();
    }

    /** Tests only. Pass null to return to real time. */
    public static function set(?int $timestamp): void
    {
        self::$fixed = $timestamp;
    }

    public static function advance(int $seconds): void
    {
        self::$fixed = self::now() + $seconds;
    }
}
