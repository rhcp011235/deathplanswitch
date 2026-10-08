<?php
declare(strict_types=1);

namespace DPS;

/**
 * Checks and small routines shared by the installer and the Settings page.
 */
final class Setup
{
    public const MIN_PHP = '8.1.0';
    public const MIN_PASSWORD = 10;

    /**
     * What the server must have.
     *
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public static function requirements(): array
    {
        $dir = store()->dir();
        $writable = (is_dir($dir) || @mkdir($dir, 0700, true)) && is_writable($dir);
        return [
            [
                'label' => 'PHP ' . self::MIN_PHP . ' or newer',
                'ok' => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
                'hint' => 'This server runs PHP ' . PHP_VERSION . '. Ask your host to switch to a current version.',
            ],
            [
                'label' => 'The OpenSSL extension, for encrypted mail',
                'ok' => extension_loaded('openssl'),
                'hint' => 'Ask your host to enable the PHP openssl extension.',
            ],
            [
                'label' => 'The mbstring extension',
                'ok' => extension_loaded('mbstring'),
                'hint' => 'Ask your host to enable the PHP mbstring extension.',
            ],
            [
                'label' => 'The data folder can be written to',
                'ok' => $writable,
                'hint' => "PHP cannot write to {$dir}. Give that folder to the user the web pages run as"
                    . (Web::runUser()['name'] !== '' ? ': sudo chown -R ' . Web::runUser()['name'] . ' ' . escapeshellarg($dir) : '.'),
            ],
        ];
    }

    public static function passwordProblem(string $password): ?string
    {
        if (strlen($password) < self::MIN_PASSWORD) {
            return 'Choose a password of at least ' . self::MIN_PASSWORD . ' characters.';
        }
        if (strlen($password) > 1000) {
            return 'That password is too long.';
        }
        return null;
    }

    /** @return string[] */
    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    public static function validTimezone(string $tz): bool
    {
        return in_array($tz, self::timezones(), true);
    }

    /**
     * Read and check SMTP settings from a posted form.
     *
     * @param array $existing saved settings; a blank password keeps the saved one
     * @return array{0: array, 1: string[]} settings, and any problems
     */
    public static function smtpFromPost(array $existing = []): array
    {
        $errors = [];
        $secure = Web::post('smtp_secure', 'tls');
        $smtp = [
            'host' => Web::post('smtp_host'),
            'port' => (int) Web::post('smtp_port', '587'),
            'secure' => in_array($secure, ['tls', 'ssl', 'none'], true) ? $secure : 'tls',
            'user' => Web::post('smtp_user'),
            'pass' => (string) ($_POST['smtp_pass'] ?? ''),
            'from_email' => strtolower(Web::post('smtp_from')),
        ];
        if ($smtp['pass'] === '' && ($existing['user'] ?? null) === $smtp['user']) {
            $smtp['pass'] = (string) ($existing['pass'] ?? '');
        }
        if ($smtp['host'] === '' || !preg_match('/^[A-Za-z0-9.\-\[\]:]+$/', $smtp['host'])) {
            $errors[] = 'Enter the mail server host, for example smtp.example.com.';
        }
        if ($smtp['port'] < 1 || $smtp['port'] > 65535) {
            $errors[] = 'Enter the mail server port. 587 is the usual one.';
        }
        if (!Web::validEmail($smtp['from_email'])) {
            $errors[] = 'Enter the address the switch sends from. Your mail service must allow it.';
        }
        return [$smtp, $errors];
    }

    /**
     * Email a six-digit code to an address the owner wants to use, and
     * remember what it is for. The address only takes effect once the code
     * comes back, because a mistyped owner address would mean check-in
     * emails going nowhere.
     *
     * @param string $which 'owner_email' or 'backup_email'
     */
    public static function startEmailCode(Store $store, Outbox $outbox, string $which, string $address): bool
    {
        $code = Tokens::code();
        if (!$outbox->send($address, Messages::testCode($code))) {
            return false;
        }
        $store->locked(static function () use ($store, $which, $address, $code): void {
            $config = $store->read('config');
            $config['pending_email'] = [
                'which' => $which,
                'address' => $address,
                'hash' => Tokens::hash($code . '|' . $address),
                'expires_at' => Clock::now() + Schedule::CODE_TTL,
                'attempts' => 0,
            ];
            $store->write('config', $config);
        });
        return true;
    }

    /**
     * Check a typed code. On success the address is saved.
     *
     * @return true|string true, or the reason it was refused
     */
    public static function finishEmailCode(Store $store, string $code): bool|string
    {
        return $store->locked(static function () use ($store, $code): bool|string {
            $config = $store->read('config');
            $pending = $config['pending_email'] ?? null;
            if (!is_array($pending)) {
                return 'There is no code waiting. Send a new one.';
            }
            if ($pending['expires_at'] < Clock::now() || $pending['attempts'] >= 6) {
                $config['pending_email'] = null;
                $store->write('config', $config);
                return 'That code has expired. Send a new one.';
            }
            $code = preg_replace('/\D/', '', $code) ?? '';
            if (!hash_equals((string) $pending['hash'], Tokens::hash($code . '|' . $pending['address']))) {
                $config['pending_email']['attempts']++;
                $store->write('config', $config);
                return 'That code is not right. Check the email and try again.';
            }
            $config[$pending['which']] = $pending['address'];
            $config['pending_email'] = null;
            $store->write('config', $config);
            $store->log('settings.email', ($pending['which'] === 'backup_email' ? 'Backup' : 'Main') . ' email address confirmed: ' . $pending['address'] . '.');
            return true;
        });
    }

    /** Run the data-folder test and save the result. */
    public static function probe(Store $store, Engine $engine, string $baseUrl): array
    {
        $result = Probe::run($store, $baseUrl);
        $store->locked(static function () use ($store, $engine, &$result): void {
            if (!$store->exists('config')) {
                return; // installer step 1: nothing to save into yet
            }
            $state = $engine->state();
            if ($result['result'] === 'unknown' && !empty($state['probe']['browser_ok_at'])) {
                $result['browser_ok_at'] = $state['probe']['browser_ok_at'];
            }
            $result['alerted_at'] = $result['result'] === 'exposed' ? ($state['probe']['alerted_at'] ?? null) : null;
            $state['probe'] = $result;
            $engine->saveState($state);
        });
        return $result;
    }

    /** Record what the owner's browser found when it requested the probe file. */
    public static function recordBrowserProbe(Store $store, Engine $engine, string $found): void
    {
        $store->locked(static function () use ($store, $engine, $found): void {
            $state = $engine->state();
            $probe = (array) ($state['probe'] ?? []);
            $now = Clock::now();
            if ($found === 'exposed') {
                $probe = ['result' => 'exposed', 'detail' => 'Your browser was able to open a file in the data folder.', 'checked_at' => $now]
                    + ['alerted_at' => $probe['alerted_at'] ?? null];
            } elseif ($found === 'protected') {
                $probe = ['result' => 'unknown', 'detail' => 'Checked from your browser: the request was refused.', 'checked_at' => $now, 'browser_ok_at' => $now, 'alerted_at' => null];
            }
            $state['probe'] = $probe;
            $engine->saveState($state);
        });
    }
}
