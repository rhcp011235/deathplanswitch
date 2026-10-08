<?php
declare(strict_types=1);

namespace DPS;

/**
 * One outgoing email, built from plain blocks so the text and HTML parts
 * always say the same thing.
 *
 * Emails are deliberately plain: no images, no branding, no tracking. They
 * are read by family members and lawyers, sometimes on a bad day.
 */
final class Email
{
    /** @var array<int, array{0: string, 1: string, 2?: string}> */
    private array $blocks = [];

    public function __construct(public string $subject)
    {
    }

    /** A paragraph of our own wording. */
    public function p(string $text): self
    {
        $this->blocks[] = ['p', $text];
        return $this;
    }

    /** Text written by the owner: blank lines separate paragraphs, line breaks are kept. */
    public function prose(string $text): self
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        foreach (preg_split('/\n{2,}/', $text) ?: [] as $para) {
            if (trim($para) !== '') {
                $this->blocks[] = ['p', trim($para)];
            }
        }
        return $this;
    }

    /** A link the reader is meant to follow. */
    public function link(string $label, string $url): self
    {
        $this->blocks[] = ['link', $label, $url];
        return $this;
    }

    /** Small print at the foot of the message. */
    public function note(string $text): self
    {
        $this->blocks[] = ['note', $text];
        return $this;
    }

    public function text(): string
    {
        $out = [];
        foreach ($this->blocks as $block) {
            $out[] = match ($block[0]) {
                'link' => $block[1] . ":\n" . $block[2],
                'note' => "--\n" . wordwrap($block[1], 76),
                default => wordwrap($block[1], 76),
            };
        }
        return implode("\n\n", $out) . "\n";
    }

    public function html(): string
    {
        $font = "font-family:-apple-system,'Segoe UI',Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#1b2430";
        $out = [];
        foreach ($this->blocks as $block) {
            $out[] = match ($block[0]) {
                'link' => '<p style="margin:0 0 18px"><a href="' . e($block[2]) . '" style="font-weight:bold;color:#1b2430">'
                    . e($block[1]) . '</a><br><span style="font-size:13px;color:#5b6673;word-break:break-all">' . e($block[2]) . '</span></p>',
                'note' => '<p style="margin:26px 0 0;padding-top:14px;border-top:1px solid #d5d9de;font-size:13px;color:#5b6673">'
                    . nl2br(e($block[1])) . '</p>',
                default => '<p style="margin:0 0 18px">' . self::autolink(nl2br(e($block[1]))) . '</p>',
            };
        }
        return '<!doctype html><html><body style="margin:0;padding:24px;background:#ffffff"><div style="max-width:560px;' . $font . '">'
            . implode("\n", $out) . '</div></body></html>';
    }

    /** Turn bare web addresses in already-escaped text into links. */
    private static function autolink(string $escaped): string
    {
        return preg_replace_callback(
            '~\bhttps?://[^\s<]+[^\s<.,;:!?)\]\'"]~i',
            static fn (array $m): string => '<a href="' . $m[0] . '" style="color:#1b2430">' . $m[0] . '</a>',
            $escaped
        ) ?? $escaped;
    }
}
