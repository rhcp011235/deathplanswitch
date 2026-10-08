<?php
/**
 * Death Plan Switch: set a new owner password from the command line.
 *
 *   php cli/reset-password.php
 *
 * There is no "forgot password" email on purpose: anyone who could read
 * your mailbox could then take over your switch. Server access is the proof.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

DPS\Cli::requireDataOwner();

$store = DPS\store();
if (!$store->exists('config')) {
    fwrite(STDERR, "Death Plan Switch is not installed here.\n");
    exit(1);
}

echo 'New password (at least ' . DPS\Setup::MIN_PASSWORD . ' characters): ';
$hide = stream_isatty(STDIN) && DIRECTORY_SEPARATOR === '/';
if ($hide) {
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($hide) {
    shell_exec('stty echo');
    echo "\n";
}

$problem = DPS\Setup::passwordProblem($password);
if ($problem !== null) {
    fwrite(STDERR, $problem . "\n");
    exit(1);
}

$store->locked(static function () use ($store, $password): void {
    $config = $store->read('config');
    $config['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
    $store->write('config', $config);
    $store->write('auth', ['failures' => 0, 'locked_until' => 0]);
    $store->log('settings.password', 'The owner password was reset from the command line.');
});

echo "Password changed. Every signed-in browser has been signed out.\n";
