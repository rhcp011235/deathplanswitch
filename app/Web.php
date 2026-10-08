<?php
declare(strict_types=1);

namespace DPS;

/**
 * Everything the web pages share: headers, sessions, sign-in, CSRF
 * protection, flash messages and the page wrapper.
 */
final class Web
{
    /** 'admin' pages get the navigation; 'plain' pages are for contacts and the check-in. */
    public static string $layout = 'plain';
    public static string $title = 'Death Plan Switch';

    // ------------------------------------------------------------------
    // Running a page
    // ------------------------------------------------------------------

    /** Render app/pages/<name>.php inside the layout. */
    public static function page(string $name): never
    {
        self::headers();
        try {
            ob_start();
            (static function (string $file): void {
                require $file;
            })(__DIR__ . '/pages/' . $name . '.php');
            $content = (string) ob_get_clean();
        } catch (StoreException $e) {
            ob_end_clean();
            http_response_code(500);
            self::$layout = 'plain';
            self::$title = 'The data folder needs attention';
            $content = '<h1>The data folder needs attention</h1><p class="lede">' . e($e->getMessage()) . '</p>'
                . '<p>Nothing has been changed. See "If a data file is damaged" in the README.</p>';
        }
        require __DIR__ . '/pages/_layout.php';
        exit;
    }

    public static function headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; img-src 'self' data:; "
            . "connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        // Emailed links carry tokens in the address. Never pass them on.
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Cache-Control: no-store');
        header('Content-Type: text/html; charset=utf-8');
    }

    // ------------------------------------------------------------------
    // Where am I?
    // ------------------------------------------------------------------

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** The address of the app folder, as the browser sees it. Used once, to suggest the base URL at install. */
    public static function guessBaseUrl(): string
    {
        $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return (self::isHttps() ? 'https' : 'http') . '://' . $host . $dir;
    }

    /** Best guess at the command-line PHP on this server, for the crontab line. */
    public static function phpCli(): ?string
    {
        $v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $vv = PHP_MAJOR_VERSION . PHP_MINOR_VERSION;
        $candidates = [];
        $binary = basename(PHP_BINARY);
        if (PHP_BINARY !== '' && str_starts_with($binary, 'php') && !preg_match('/fpm|cgi/', $binary)) {
            $candidates[] = PHP_BINARY;
        }
        array_push(
            $candidates,
            PHP_BINDIR . '/php' . $v,
            PHP_BINDIR . '/php',
            "/usr/bin/php{$v}",
            "/usr/local/bin/php{$v}",
            "/opt/cpanel/ea-php{$vv}/root/usr/bin/php",
            "/opt/alt/php{$vv}/usr/bin/php",
            "/opt/plesk/php/{$v}/bin/php",
            "/usr/local/php{$vv}/bin/php",
            '/usr/local/bin/php',
            '/usr/bin/php'
        );
        foreach ($candidates as $path) {
            if (@is_file($path) && @is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    /**
     * The exact line for the owner's crontab. Every 15 minutes: each run is
     * two small file reads, and a short interval means a missed run costs
     * minutes, not an hour.
     */
    public static function cronLine(): string
    {
        return sprintf('*/15 * * * * %s %s >/dev/null 2>&1', self::phpCli() ?? 'php', self::shellPath(DPS_ROOT . '/cron.php'));
    }

    /** A command the owner can run once by hand to test the scheduler straight away. */
    public static function cronCommand(): string
    {
        $user = self::runUser();
        return ($user['differs'] ? 'sudo -u ' . $user['name'] . ' ' : '')
            . (self::phpCli() ?? 'php') . ' ' . self::shellPath(DPS_ROOT . '/cron.php') . ' --verbose';
    }

    /** A path as it should be typed in a shell: quoted only when it needs to be. */
    private static function shellPath(string $path): string
    {
        return preg_match('~^[A-Za-z0-9_./-]+$~', $path) ? $path : escapeshellarg($path);
    }

    /** The command that opens the right crontab for editing. */
    public static function crontabCommand(): string
    {
        $user = self::runUser();
        return $user['differs'] ? 'sudo crontab -u ' . $user['name'] . ' -e' : 'crontab -e';
    }

    /**
     * The user PHP runs as on the web, and whether that differs from the
     * owner of the app's files. The scheduler has to run as the web user,
     * because both write to the data folder.
     *
     * @return array{name: string, differs: bool}
     */
    public static function runUser(): array
    {
        if (!function_exists('posix_geteuid')) {
            return ['name' => '', 'differs' => false];
        }
        $uid = posix_geteuid();
        $name = posix_getpwuid($uid)['name'] ?? (string) $uid;
        $owner = @fileowner(DPS_ROOT . '/cron.php');
        return ['name' => $name, 'differs' => $owner !== false && $owner !== $uid];
    }

    // ------------------------------------------------------------------
    // Sessions and sign-in
    // ------------------------------------------------------------------

    public static function session(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/') . '/';
        session_name('dps');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path,
            'secure' => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();

        // Sign out after two hours of inactivity.
        if (!empty($_SESSION['owner']) && Clock::now() - (int) ($_SESSION['seen'] ?? 0) > 2 * Schedule::HOUR) {
            $_SESSION = [];
            session_regenerate_id(true);
        }
        $_SESSION['seen'] = Clock::now();
    }

    public static function signedIn(): bool
    {
        self::session();
        $config = store()->read('config');
        // Changing the password signs out every other session.
        return !empty($_SESSION['owner'])
            && hash_equals(substr((string) ($config['password_hash'] ?? ''), -16), (string) ($_SESSION['pw'] ?? ''));
    }

    public static function signIn(): void
    {
        self::session();
        session_regenerate_id(true);
        $_SESSION['owner'] = true;
        $_SESSION['pw'] = substr((string) (store()->read('config')['password_hash'] ?? ''), -16);
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }

    public static function signOut(): void
    {
        self::session();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    /** Send visitors who are not signed in to the sign-in page. */
    public static function requireOwner(): void
    {
        $config = store()->read('config');
        if (empty($config['installed_at'])) {
            self::redirect('install.php');
        }
        if (!self::signedIn()) {
            self::redirect('index.php?p=login');
        }
        self::$layout = 'admin';
    }

    /**
     * Check a password, with a growing delay after repeated failures.
     *
     * @return true|string true, or the reason sign-in is refused
     */
    public static function tryPassword(string $password): bool|string
    {
        $store = store();
        return $store->locked(static function () use ($store, $password): bool|string {
            $now = Clock::now();
            $auth = $store->read('auth') + ['failures' => 0, 'locked_until' => 0];
            if ($auth['locked_until'] > $now) {
                return 'Too many wrong passwords. Try again ' . relative((int) $auth['locked_until']) . '.';
            }
            $hash = (string) ($store->read('config')['password_hash'] ?? '');
            if ($hash !== '' && password_verify($password, $hash)) {
                if ($auth['failures'] > 0) {
                    $store->write('auth', ['failures' => 0, 'locked_until' => 0]);
                }
                return true;
            }
            $auth['failures']++;
            if ($auth['failures'] >= 5) {
                // 5 wrong: 1 minute. Each further wrong one doubles it, up to an hour.
                $auth['locked_until'] = $now + min(3600, 60 * 2 ** ($auth['failures'] - 5));
            }
            $store->write('auth', $auth);
            $store->log('auth.failed', 'A wrong password was entered at sign-in.');
            return 'That password is not right.';
        });
    }

    // ------------------------------------------------------------------
    // Forms
    // ------------------------------------------------------------------

    public static function csrf(): string
    {
        self::session();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf" value="' . e(self::csrf()) . '">';
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /** True for a POST with a valid CSRF token. A POST without one is rejected outright. */
    public static function posted(): bool
    {
        if (!self::isPost()) {
            return false;
        }
        self::session();
        $sent = (string) ($_POST['csrf'] ?? '');
        if ($sent === '' || !hash_equals((string) ($_SESSION['csrf'] ?? ''), $sent)) {
            http_response_code(400);
            self::$layout = 'plain';
            self::flash('That form had expired. Go back, reload the page and try again.', 'bad');
            self::redirect('index.php');
        }
        return true;
    }

    public static function post(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;
        return is_string($value) ? trim(str_replace("\0", '', $value)) : $default;
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        return is_string($value) ? trim(str_replace("\0", '', $value)) : $default;
    }

    public static function validEmail(string $email): bool
    {
        return strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    // ------------------------------------------------------------------
    // Messages shown after a redirect
    // ------------------------------------------------------------------

    /** @param string $type 'ok', 'warn' or 'bad' */
    public static function flash(string $message, string $type = 'ok'): void
    {
        self::session();
        $_SESSION['flash'][] = [$type, $message];
    }

    /** @return array<int, array{0: string, 1: string}> */
    public static function flashes(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $flashes = $_SESSION['flash'] ?? [];
        unset($_SESSION['flash']);
        return $flashes;
    }

    public static function redirect(string $to): never
    {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Location: ' . $to, true, 303);
        exit;
    }
}
