<?php
/**
 * Death Plan Switch: bootstrap.
 *
 * Loaded by every entry point (web and CLI). It defines paths, the class
 * autoloader and a handful of small helpers. It has no side effects beyond
 * that, so requesting this file directly over the web does nothing.
 */
declare(strict_types=1);

namespace DPS;

const VERSION = '1.0.0';

if (!defined('DPS_ROOT')) {
    define('DPS_ROOT', dirname(__DIR__));
}

// Optional: config.local.php may define DPS_DATA to move the data folder
// outside the web root. See README, "Moving the data folder".
if (is_file(DPS_ROOT . '/config.local.php')) {
    require DPS_ROOT . '/config.local.php';
}
if (!defined('DPS_DATA')) {
    define('DPS_DATA', DPS_ROOT . '/data');
}

error_reporting(E_ALL);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC'); // everything is stored in UTC; display uses the owner's timezone

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'DPS\\')) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    } elseif (str_starts_with($class, 'PHPMailer\\PHPMailer\\')) {
        $file = __DIR__ . '/lib/PHPMailer/' . substr($class, 20) . '.php';
    } else {
        return;
    }
    if (is_file($file)) {
        require $file;
    }
});

/** Escape for HTML output. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** The one shared store for this install. */
function store(): Store
{
    static $store = null;
    return $store ??= new Store(DPS_DATA);
}

/** The engine, wired to real SMTP using the saved settings. */
function engine(): Engine
{
    static $engine = null;
    if ($engine === null) {
        $store = store();
        $engine = new Engine($store, new Outbox($store, new SmtpTransport(), new Telegram()));
    }
    return $engine;
}

/** Format a UTC timestamp in the owner's timezone. */
function when(?int $ts, string $tz = 'UTC', string $format = 'l j F Y, g:ia'): string
{
    if (!$ts) {
        return 'never';
    }
    try {
        $zone = new \DateTimeZone($tz);
    } catch (\Throwable) {
        $zone = new \DateTimeZone('UTC');
    }
    return (new \DateTimeImmutable('@' . $ts))->setTimezone($zone)->format($format);
}

/** "3 hours", "2 days": a rough human duration. */
function duration(int $seconds): string
{
    $seconds = abs($seconds);
    if ($seconds < 90) {
        return 'a minute';
    }
    if ($seconds < 3600 * 1.5) {
        return round($seconds / 60) . ' minutes';
    }
    if ($seconds < 86400 * 2) {
        return round($seconds / 3600) . ' hours';
    }
    return round($seconds / 86400) . ' days';
}

/** "in 3 hours" or "3 hours ago". */
function relative(?int $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $diff = $ts - Clock::now();
    if (abs($diff) < 60) {
        return 'just now';
    }
    return $diff >= 0 ? 'in ' . duration($diff) : duration($diff) . ' ago';
}

/** 9 becomes "9:00am". */
function hourLabel(int $hour): string
{
    return gmdate('g:ia', gmmktime(min(23, max(0, $hour)), 0, 0, 1, 1, 2026));
}
