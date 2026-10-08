<?php
/**
 * Death Plan Switch: the scheduler, by web address.
 *
 * For hosting without cron, and for outside schedulers. Requesting
 *
 *   tick.php?key=<your secret key>
 *
 * does exactly what cron.php does. Settings shows the full address.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

try {
    $config = DPS\store()->exists('config') ? DPS\store()->read('config') : [];
    $key = is_string($_GET['key'] ?? null) ? $_GET['key'] : '';
    if (empty($config['tick_key']) || !hash_equals((string) $config['tick_key'], $key)) {
        http_response_code(404);
        exit("Not found\n");
    }
    DPS\engine()->tick('url');
    echo "OK\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "Error\n";
}
