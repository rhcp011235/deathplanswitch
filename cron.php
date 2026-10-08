<?php
/**
 * Death Plan Switch: the scheduler.
 *
 * Run this from cron every 15 minutes. The installer shows the exact line.
 *
 *   php cron.php            quiet, for cron
 *   php cron.php --verbose  prints what it did, for testing by hand
 *
 * It only runs from the command line. Requested over the web it does nothing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/app/bootstrap.php';

DPS\Cli::requireDataOwner();

try {
    $did = DPS\engine()->tick('cron');
    if (in_array('--verbose', $argv, true) || in_array('-v', $argv, true)) {
        echo date('Y-m-d H:i:s') . " UTC\n- " . implode("\n- ", $did) . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    // Cron mails anything written to stderr to the server's owner, which is what we want here.
    fwrite(STDERR, 'Death Plan Switch could not run: ' . $e->getMessage() . "\n");
    exit(1);
}
