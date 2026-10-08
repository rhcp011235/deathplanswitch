<?php
declare(strict_types=1);

namespace DPS;

/**
 * Tests whether the data folder can be read from the web.
 *
 * The data folder holds contact details, messages and token hashes. On
 * Apache the bundled .htaccess blocks it; on nginx, or on Apache with
 * overrides turned off, nothing does until the owner adds a rule. So the
 * app asks instead of assuming: it keeps a harmless probe file in the data
 * folder and requests it over the web, exactly as a stranger would.
 *
 *   protected  the request was refused, or returned something else
 *   exposed    the probe file's contents came back
 *   outside    the data folder is not under the app folder, so has no URL
 *   unknown    the server could not reach its own URL (the browser check
 *              on the dashboard covers this case)
 */
final class Probe
{
    public const FILE = 'probe';

    /** Create the probe file if needed and return its secret. */
    public static function secret(Store $store): string
    {
        $doc = $store->exists(self::FILE) ? $store->read(self::FILE) : [];
        if (!is_string($doc['probe'] ?? null) || strlen($doc['probe']) < 16) {
            $doc = [
                'probe' => bin2hex(random_bytes(16)),
                'about' => 'Death Plan Switch requests this file over the web to check the data folder is blocked. It holds nothing private.',
            ];
            $store->write(self::FILE, $doc);
        }
        return $doc['probe'];
    }

    /** The web address the probe file would have, or null if the data folder is outside the app folder. */
    public static function url(Store $store, string $baseUrl): ?string
    {
        $root = realpath(DPS_ROOT);
        $data = realpath($store->dir());
        if ($root === false || $data === false || !str_starts_with($data . '/', $root . '/')) {
            return null;
        }
        $relative = trim(str_replace('\\', '/', substr($data, strlen($root))), '/');
        return rtrim($baseUrl, '/') . '/' . $relative . '/' . self::FILE . '.json';
    }

    /** @return array{result: string, detail: string, checked_at: int} */
    public static function run(Store $store, string $baseUrl): array
    {
        $secret = self::secret($store);
        $url = self::url($store, $baseUrl);
        $at = Clock::now();
        if ($url === null) {
            return ['result' => 'outside', 'detail' => 'The data folder is outside the app folder.', 'checked_at' => $at];
        }
        [$status, $body, $error] = self::fetch($url);
        if ($status === 0) {
            return ['result' => 'unknown', 'detail' => 'The server could not request its own address: ' . $error, 'checked_at' => $at];
        }
        if (str_contains($body, $secret)) {
            return ['result' => 'exposed', 'detail' => 'A file in the data folder was served at ' . $url, 'checked_at' => $at];
        }
        return ['result' => 'protected', 'detail' => "The request was refused (HTTP {$status}).", 'checked_at' => $at];
    }

    /** @return array{0: int, 1: string, 2: string} status (0 on failure), body, error */
    private static function fetch(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_USERAGENT => 'DeathPlanSwitch-probe',
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false || $status === 0) {
                return [0, '', $error ?: 'no response'];
            }
            return [$status, substr((string) $body, 0, 4096), ''];
        }

        $context = stream_context_create(['http' => [
            'timeout' => 8,
            'ignore_errors' => true,
            'max_redirects' => 3,
            'user_agent' => 'DeathPlanSwitch-probe',
        ]]);
        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            return [0, '', error_get_last()['message'] ?? 'no response'];
        }
        $headers = stream_get_meta_data($handle)['wrapper_data'] ?? [];
        $body = stream_get_contents($handle, 4096);
        fclose($handle);
        // With redirects there are several status lines; the last one counts.
        $m = null;
        foreach ((array) $headers as $header) {
            if (is_string($header) && preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $mm)) {
                $m = $mm;
            }
        }
        if ($m === null) {
            return [0, '', 'no response'];
        }
        return [(int) $m[1], (string) $body, ''];
    }
}
