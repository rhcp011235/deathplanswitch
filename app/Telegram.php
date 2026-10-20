<?php
declare(strict_types=1);

namespace DPS;

/**
 * Sends to the owner through their own Telegram bot.
 *
 * The bot only ever talks; it never listens. Nothing has to reach this
 * server from Telegram, so it works behind any firewall. Linking a chat is
 * done once, from Settings: the owner opens a t.me link carrying a one-off
 * code, presses Start, and the app finds that code in the bot's updates.
 */
final class Telegram implements Messenger
{
    private const API = 'https://api.telegram.org/bot';

    /** Telegram refuses messages longer than 4096 characters. */
    private const MAX_TEXT = 4000;

    public function send(array $settings, string $text, ?array $button): void
    {
        $params = [
            'chat_id' => (string) ($settings['chat_id'] ?? ''),
            'text' => mb_substr($text, 0, self::MAX_TEXT),
            // Telegram would otherwise fetch the check-in link to draw a preview.
            'link_preview_options' => ['is_disabled' => true],
        ];
        if ($button !== null) {
            $params['reply_markup'] = ['inline_keyboard' => [[['text' => $button['label'], 'url' => $button['url']]]]];
        }
        try {
            self::call((string) ($settings['bot_token'] ?? ''), 'sendMessage', $params);
        } catch (\RuntimeException $e) {
            if ($button === null || stripos($e->getMessage(), 'button') === false) {
                throw $e;
            }
            // Telegram rejects button links it does not like (a local address, say).
            // The link is in the text as well, so send without the button.
            unset($params['reply_markup']);
            self::call((string) ($settings['bot_token'] ?? ''), 'sendMessage', $params);
        }
    }

    public static function validToken(string $token): bool
    {
        return (bool) preg_match('/^\d{5,15}:[A-Za-z0-9_-]{30,50}$/', $token);
    }

    /** The bot's username, which proves the token works. */
    public static function botName(string $token): string
    {
        return (string) (self::call($token, 'getMe')['username'] ?? '');
    }

    /**
     * Look through the bot's recent messages for "/start <code>".
     *
     * @return string|null the chat it came from, or null if it has not arrived
     */
    public static function findStart(string $token, string $code): ?string
    {
        $found = null;
        foreach ((array) self::call($token, 'getUpdates', ['allowed_updates' => ['message']]) as $update) {
            $message = $update['message'] ?? null;
            if (is_array($message) && trim((string) ($message['text'] ?? '')) === '/start ' . $code
                && ($message['chat']['type'] ?? '') === 'private') {
                $found = (string) $message['chat']['id'];
            }
        }
        return $found;
    }

    /**
     * One Bot API request.
     *
     * @throws \RuntimeException with Telegram's own reason. Never includes the token.
     */
    private static function call(string $token, string $method, array $params = []): mixed
    {
        if (!self::validToken($token)) {
            throw new \RuntimeException('The bot token is missing or not in the right form.');
        }
        $url = self::API . $token . '/' . $method;
        $body = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
            ]);
            $raw = curl_exec($ch);
            $error = curl_error($ch);
        } else {
            $context = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 20,
                'ignore_errors' => true, // Telegram explains its errors in the body
            ]]);
            $raw = @file_get_contents($url, false, $context);
            $error = $raw === false ? 'the request failed' : '';
        }

        if (!is_string($raw) || $raw === '') {
            throw new \RuntimeException('Telegram could not be reached: ' . str_replace($token, '…', $error ?: 'no response') . '.');
        }
        $reply = json_decode($raw, true);
        if (!is_array($reply)) {
            throw new \RuntimeException('Telegram sent back something unexpected.');
        }
        if (empty($reply['ok'])) {
            throw new \RuntimeException('Telegram said: ' . (string) ($reply['description'] ?? 'unknown error'));
        }
        return $reply['result'] ?? null;
    }
}
