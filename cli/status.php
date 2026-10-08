<?php
/**
 * Death Plan Switch: print the state of the switch.
 *
 *   php cli/status.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

DPS\Cli::requireDataOwner();

$engine = DPS\engine();
if (!$engine->installed()) {
    echo "Not installed.\n";
    exit(1);
}

$config = $engine->config();
$tz = (string) ($config['timezone'] ?? 'UTC');
$status = $engine->status();
$state = $status['state'];

echo 'Death Plan Switch ' . DPS\VERSION . "\n";
echo 'State:           ' . ['setup' => 'not armed', 'armed' => 'armed', 'paused' => 'paused', 'overdue' => empty($state['cycle']['reminders']) ? 'waiting for this week\'s check-in' : 'check-in overdue', 'verify' => 'next of kin asked', 'fired' => 'FIRED'][$status['mode']] . "\n";
echo 'Last check-in:   ' . DPS\when($state['last_checkin_at'], $tz) . "\n";
if ($status['mode'] === 'armed') {
    echo 'Next email:      ' . DPS\when($state['next_due_at'], $tz) . "\n";
}
if ($status['mode'] === 'paused') {
    echo 'Paused until:    ' . DPS\when($state['paused_until'], $tz) . "\n";
}
foreach ($status['plan'] as $key => $step) {
    if ($state['cycle'] !== null) {
        printf("  %-14s %s %s\n", $key, $step['done'] ? 'done' : 'due ', DPS\when($step['at'], $tz, 'D j M, g:ia'));
    }
}
echo 'Scheduler ran:   ' . DPS\relative($state['last_tick_at']) . "\n";
$health = $engine->health();
echo 'Health:          ' . ($health['ok'] ? 'ok' : 'PROBLEM: ' . implode(', ', $health['problems'])) . "\n";
foreach ($engine->warnings() as $warning) {
    echo '  - ' . $warning['text'] . "\n";
}
exit($health['ok'] ? 0 : 2);
