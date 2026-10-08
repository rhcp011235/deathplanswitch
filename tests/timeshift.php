<?php
/**
 * Death Plan Switch: make an install believe time has passed.
 *
 *   php tests/timeshift.php 24      pretend 24 hours have gone by
 *   php tests/timeshift.php 72 --run   ...and run the scheduler straight after
 *
 * FOR TESTING ONLY. It moves every stored timestamp back by the given number
 * of hours, so the next scheduler run sees reminders, the next-of-kin stage
 * and so on as due. Use it to rehearse a full escalation in an afternoon
 * instead of thirteen days. Real emails are sent to your real contacts, so
 * rehearse with addresses you control, or tell people first.
 *
 * This folder is left out of release downloads.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

DPS\Cli::requireDataOwner();

$hours = (float) ($argv[1] ?? 0);
if ($hours <= 0) {
    fwrite(STDERR, "Usage: php tests/timeshift.php <hours> [--run]\n");
    exit(1);
}
$seconds = (int) round($hours * 3600);
$store = DPS\store();

/** Shift every "*_at" and "*_until" value, and every list of timestamps under "reminders" or "notified". */
$shift = static function (array $data) use (&$shift, $seconds): array {
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            if ($key === 'reminders' || $key === 'notified') {
                $data[$key] = array_map(static fn ($t) => is_int($t) ? $t - $seconds : $t, $value);
            } else {
                $data[$key] = $shift($value);
            }
        } elseif (is_int($value) && is_string($key) && preg_match('/(_at|_until)$/', $key) && $value > 1_000_000_000) {
            $data[$key] = $value - $seconds;
        }
    }
    return $data;
};

$store->locked(static function () use ($store, $shift): void {
    foreach (['state', 'contacts'] as $name) {
        if ($store->exists($name)) {
            $store->write($name, $shift($store->read($name)));
        }
    }
});
$store->log('test.timeshift', "TEST: the clock was moved on by {$hours} hours.");
echo "Moved on by {$hours} hours.\n";

if (in_array('--run', $argv, true)) {
    echo '- ' . implode("\n- ", DPS\engine()->tick('cron')) . "\n";
}
