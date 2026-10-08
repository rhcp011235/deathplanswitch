<?php
/**
 * Death Plan Switch: health check for uptime monitors.
 *
 *   health.php?key=<your health key>
 *
 * Answers 200 when all is well, and 503 when the scheduler has stopped,
 * mail is failing, or the data folder is exposed. It changes nothing and
 * reveals nothing private. Settings shows the full address.
 *
 * Why it exists: if the scheduler stops, the switch cannot tell anyone.
 * Something outside the server has to notice.
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

try {
    $config = DPS\store()->exists('config') ? DPS\store()->read('config') : [];
    $key = is_string($_GET['key'] ?? null) ? $_GET['key'] : '';
    if (empty($config['health_key']) || !hash_equals((string) $config['health_key'], $key)) {
        http_response_code(404);
        exit('{"error":"not found"}');
    }
    $health = DPS\engine()->health();
    http_response_code($health['ok'] ? 200 : 503);
    echo json_encode($health);
} catch (Throwable $e) {
    http_response_code(503);
    echo '{"ok":false,"problems":["store"]}';
}
