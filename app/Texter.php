<?php
declare(strict_types=1);

namespace DPS;

/**
 * Something that can send a text message. The real one uses Textbelt; the
 * tests use one that records texts instead of sending them.
 */
interface Texter
{
    /**
     * @param array  $settings The saved text settings.
     * @param string $to       A phone number in +E.164 form.
     *
     * @return int|null how many texts the account has left, if the service says
     *
     * @throws \RuntimeException with a readable reason when the text is refused.
     */
    public function text(array $settings, string $to, string $body): ?int;
}
