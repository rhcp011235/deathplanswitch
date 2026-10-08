<?php
declare(strict_types=1);

namespace DPS;

/**
 * Something that can deliver an email. The real one speaks SMTP; the tests
 * use one that records messages instead of sending them.
 */
interface Transport
{
    /**
     * @param array  $smtp     The saved SMTP settings.
     * @param string[] $to     One or more recipient addresses.
     * @param string $fromName Display name for the From header.
     * @param string $replyTo  Reply-To address, or '' for none.
     *
     * @throws \RuntimeException with a readable reason when delivery fails.
     */
    public function send(array $smtp, array $to, string $fromName, string $replyTo, Email $email): void;
}
