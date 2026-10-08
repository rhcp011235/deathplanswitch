<?php
declare(strict_types=1);

namespace DPS;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * Sends through the owner's SMTP service using the bundled PHPMailer.
 * PHP's built-in mail() is never used: it fails silently on too many hosts.
 */
final class SmtpTransport implements Transport
{
    public function send(array $smtp, array $to, string $fromName, string $replyTo, Email $email): void
    {
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = (string) ($smtp['host'] ?? '');
            $mail->Port = (int) ($smtp['port'] ?? 587);
            $mail->Timeout = 20;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->XMailer = ' '; // no X-Mailer header

            $secure = (string) ($smtp['secure'] ?? 'tls');
            if ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $user = (string) ($smtp['user'] ?? '');
            if ($user !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $user;
                $mail->Password = (string) ($smtp['pass'] ?? '');
            }

            $from = (string) ($smtp['from_email'] ?? '');
            // Name ourselves after the sending domain, not whatever the server happens to be called.
            $domain = substr((string) strrchr($from, '@'), 1);
            if ($domain !== '') {
                $mail->Hostname = $domain;
            }
            $mail->setFrom($from, $fromName);
            foreach ($to as $address) {
                $mail->addAddress($address);
            }
            if ($replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }
            // Tell mail systems this is an automated message, so out-of-office
            // replies and the like are not sent back to it.
            $mail->addCustomHeader('Auto-Submitted', 'auto-generated');

            $mail->Subject = $email->subject;
            $mail->isHTML(true);
            $mail->Body = $email->html();
            $mail->AltBody = $email->text();
            $mail->send();
        } catch (\Throwable $e) {
            $reason = trim(strip_tags($mail->ErrorInfo ?: $e->getMessage()));
            throw new \RuntimeException($reason !== '' ? $reason : 'The mail server did not accept the message.');
        }
    }
}
