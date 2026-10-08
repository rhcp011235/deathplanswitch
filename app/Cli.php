<?php
declare(strict_types=1);

namespace DPS;

/**
 * Shared by the command-line scripts.
 */
final class Cli
{
    /**
     * Stop unless this script runs as the user that owns the data files.
     *
     * The web server and the scheduler both read and write the data folder.
     * If cron runs as a different user (root, or your own login), it either
     * cannot read the files or, worse, rewrites them so the web server
     * cannot. Refusing here turns a silent fault into a clear message.
     */
    public static function requireDataOwner(): void
    {
        if (!function_exists('posix_geteuid')) {
            return; // cannot tell on this system; carry on
        }
        $dir = store()->dir();
        $file = is_file($dir . '/config.json') ? $dir . '/config.json' : $dir;
        $owner = @fileowner($file);
        if ($owner === false || $owner === posix_geteuid()) {
            return;
        }
        $ownerName = posix_getpwuid($owner)['name'] ?? (string) $owner;
        $me = posix_getpwuid(posix_geteuid())['name'] ?? (string) posix_geteuid();
        fwrite(STDERR, "Death Plan Switch: this script is running as \"{$me}\", but the data folder belongs to \"{$ownerName}\".\n"
            . "Run it as {$ownerName} instead, for example:\n"
            . "  sudo -u {$ownerName} " . PHP_BINARY . ' ' . ($_SERVER['argv'][0] ?? 'cron.php') . "\n"
            . "and put the crontab line in that user's crontab:\n"
            . "  sudo crontab -u {$ownerName} -e\n");
        exit(1);
    }
}
