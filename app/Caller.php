<?php
declare(strict_types=1);

namespace DPS;

/**
 * Something that can phone someone and read them a message. The real one
 * uses Twilio; the tests use one that records calls instead of placing them.
 */
interface Caller
{
    /**
     * Start a call. It rings in the background; ask status() how it went.
     *
     * @param array  $settings The saved call settings.
     * @param string $to       A phone number in +E.164 form.
     * @param string $script   What the voice says.
     * @return string an id for the call
     *
     * @throws \RuntimeException with a readable reason when the call is refused.
     */
    public function call(array $settings, string $to, string $script): string;

    /**
     * @return string 'pending' while it rings or is in progress, 'answered'
     *                (by a person or a voicemail), or 'missed' (busy, no answer, failed)
     *
     * @throws \RuntimeException when the status cannot be read.
     */
    public function status(array $settings, string $id): string;
}
