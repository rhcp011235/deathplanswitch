<?php
declare(strict_types=1);

namespace DPS;

/**
 * Every timing rule in one place.
 *
 * These are fixed in version one. The escalation, counted from the weekly
 * check-in email:
 *
 *      0h   check-in email to the owner
 *    +24h   reminder to the owner (both addresses)
 *    +48h   reminder to the owner (both addresses)
 *    +72h   next of kin asked "is everything all right?"
 *   +120h   next of kin reminded
 *   +144h   remaining contacts receive their messages (6 days)
 *
 * The check-in email goes out at the same day and hour each week, so the
 * contacts are told at most 13 days after the owner's last check-in.
 */
final class Schedule
{
    public const HOUR = 3600;
    public const DAY = 86400;

    /** Reminders to the owner, in seconds after the check-in email. */
    public const OWNER_REMINDERS = [24 * self::HOUR, 48 * self::HOUR];

    /** How long the owner has before the next of kin is asked. */
    public const OWNER_WINDOW = 72 * self::HOUR;

    /** How long the next of kin has to answer. */
    public const NOK_WINDOW = 72 * self::HOUR;

    /** One reminder to the next of kin, in seconds after they were asked. */
    public const NOK_REMINDER = 48 * self::HOUR;

    /**
     * The least time between two escalation steps. In normal running this
     * never applies. After a cron outage it stops the app from catching up
     * by sending every overdue step at once: each warning still gets time
     * to be seen before the next, more serious one goes out.
     */
    public const MIN_STEP_GAP = 12 * self::HOUR;

    /** Check-in links stop working after this long. */
    public const CHECKIN_TOKEN_TTL = 14 * self::DAY;

    /** The "I'm here" link sent once the switch has fired. */
    public const ALL_CLEAR_TOKEN_TTL = 30 * self::DAY;

    /** Links sent to contacts asking them to confirm. */
    public const CONFIRM_TOKEN_TTL = 30 * self::DAY;

    /** Email verification codes. */
    public const CODE_TTL = 30 * 60;

    /** A pause can never be longer than this. */
    public const PAUSE_MAX_DAYS = 28;

    /** Warn on every page when the scheduler has not run for this long. */
    public const CRON_STALE = self::DAY;

    /** Contacts reconfirm, and the owner reviews, once a year. */
    public const YEAR = 365 * self::DAY;

    /** Reconfirmation requests get one nudge after this long. */
    public const RECONFIRM_NUDGE = 14 * self::DAY;

    /** How often the data folder is re-tested from the web. */
    public const PROBE_EVERY = self::DAY;

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /**
     * The next weekly check-in time strictly after $after.
     *
     * @param int $dow  1 (Monday) to 7 (Sunday), in the owner's timezone
     * @param int $hour 0 to 23, in the owner's timezone
     */
    public static function nextSlot(int $after, string $tz, int $dow, int $hour): int
    {
        try {
            $zone = new \DateTimeZone($tz);
        } catch (\Throwable) {
            $zone = new \DateTimeZone('UTC');
        }
        $dow = min(7, max(1, $dow));
        $hour = min(23, max(0, $hour));
        $day = (new \DateTimeImmutable('@' . $after))->setTimezone($zone)->setTime($hour, 0);
        for ($i = 0; $i < 9; $i++) {
            if ((int) $day->format('N') === $dow && $day->getTimestamp() > $after) {
                return $day->getTimestamp();
            }
            $day = $day->modify('+1 day')->setTime($hour, 0);
        }
        return $after + 7 * self::DAY; // unreachable, but never return something in the past
    }
}
