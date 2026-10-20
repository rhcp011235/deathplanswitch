<?php
declare(strict_types=1);

namespace DPS;

/**
 * Something that can deliver a short chat message to the owner, alongside
 * email. The real one speaks to Telegram; the tests use one that records
 * messages instead of sending them.
 */
interface Messenger
{
    /**
     * @param array $settings The saved settings for this channel.
     * @param array{label: string, url: string}|null $button A link to show as a button, if the channel can.
     *
     * @throws \RuntimeException with a readable reason when delivery fails.
     */
    public function send(array $settings, string $text, ?array $button): void;
}
