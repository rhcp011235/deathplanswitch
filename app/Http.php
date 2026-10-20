<?php
declare(strict_types=1);

namespace DPS;

/**
 * The few outside services the switch talks to (Telegram, Twilio, Textbelt)
 * all answer JSON over HTTPS. This makes those requests with curl, or with
 * PHP's own streams where curl is missing.
 */
final class Http
{
    /**
     * @param array<string, mixed>|null $form  sent as a form; null for a GET
     * @param string|null $json                sent as a JSON body instead of a form
     * @param string|null $auth                "user:password" for HTTP basic auth
     * @return array{status: int, body: array|null, error: string}
     *         status is 0 when the service could not be reached at all
     */
    public static function request(string $url, ?array $form = null, ?string $json = null, ?string $auth = null): array
    {
        $headers = [];
        $content = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $content = $json;
        } elseif ($form !== null) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            $content = http_build_query($form);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);
            if ($content !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $content);
            }
            if ($auth !== null) {
                curl_setopt($ch, CURLOPT_USERPWD, $auth);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
        } else {
            if ($auth !== null) {
                $headers[] = 'Authorization: Basic ' . base64_encode($auth);
            }
            $context = stream_context_create(['http' => [
                'method' => $content !== null ? 'POST' : 'GET',
                'header' => implode("\r\n", $headers),
                'content' => $content ?? '',
                'timeout' => 20,
                'ignore_errors' => true, // these services explain their errors in the body
            ]]);
            $raw = @file_get_contents($url, false, $context);
            $status = preg_match('~^HTTP/\S+ (\d{3})~', (string) ($http_response_header[0] ?? ''), $m) ? (int) $m[1] : 0;
            $error = $raw === false ? 'the request failed' : '';
        }

        if (!is_string($raw) || $raw === '') {
            return ['status' => 0, 'body' => null, 'error' => $error ?: 'no response'];
        }
        $body = json_decode($raw, true);
        return ['status' => $status, 'body' => is_array($body) ? $body : null, 'error' => ''];
    }
}
