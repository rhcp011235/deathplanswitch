<?php
declare(strict_types=1);

namespace DPS;

/**
 * Flat-file storage. One JSON file per document, in the data folder.
 *
 * Two rules from the brief are enforced here and nowhere else:
 *
 *  1. Writes are atomic. A file is written to a temporary name, flushed to
 *     disk, then renamed over the original. A reader sees either the old
 *     file or the new one, never half of each. If the write fails (disk
 *     full, for instance) the original is left untouched.
 *
 *  2. Read-modify-write sequences run under one exclusive lock, so the cron
 *     job and a web request cannot overwrite each other's changes.
 *
 * As a last line of defence, every write first keeps the previous good copy
 * as "<name>.json.bak". If a file is ever unreadable, the backup is used and
 * the event is logged, because a silently empty state file would mean a
 * silently disabled switch.
 */
final class Store
{
    /** @var resource|null */
    private $lockHandle = null;
    private int $lockDepth = 0;
    /** @var array<string, array> */
    private array $recovered = [];

    public function __construct(private string $dir)
    {
    }

    public function dir(): string
    {
        return $this->dir;
    }

    public function path(string $name): string
    {
        return $this->dir . '/' . $name . '.json';
    }

    public function exists(string $name): bool
    {
        return is_file($this->path($name));
    }

    /**
     * @throws StoreException if the file and its backup are both unreadable.
     */
    public function read(string $name, array $default = []): array
    {
        $file = $this->path($name);
        if (!is_file($file)) {
            // A missing file with a backup beside it is damage, not a fresh start.
            if (is_file($file . '.bak')) {
                return $this->recover($name);
            }
            return $default;
        }
        $data = self::decode(@file_get_contents($file));
        if ($data === null) {
            return $this->recover($name);
        }
        return $data;
    }

    public function write(string $name, array $data): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new StoreException("Cannot create the data folder at {$this->dir}.");
        }
        $file = $this->path($name);
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";

        $tmp = $file . '.tmp.' . bin2hex(random_bytes(6));
        $handle = @fopen($tmp, 'wb');
        if ($handle === false) {
            throw new StoreException("Cannot write to the data folder at {$this->dir}.");
        }
        try {
            @chmod($tmp, 0600);
            $written = fwrite($handle, $json);
            if ($written !== strlen($json) || !fflush($handle)) {
                throw new StoreException("Could not write {$name}.json in full. Is the disk full?");
            }
            if (function_exists('fsync')) {
                @fsync($handle);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($tmp);
            throw $e;
        }
        fclose($handle);

        // Keep the last good copy before replacing it.
        if (is_file($file) && self::decode(@file_get_contents($file)) !== null) {
            @copy($file, $file . '.bak');
            @chmod($file . '.bak', 0600);
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new StoreException("Could not replace {$name}.json.");
        }
    }

    /**
     * Run $fn while holding the exclusive lock. Re-entrant, so locked code
     * can call other locked code.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public function locked(callable $fn): mixed
    {
        $this->lock();
        try {
            return $fn();
        } finally {
            $this->unlock();
        }
    }

    /** Try to take the lock without waiting. Used so two cron runs never overlap. */
    public function tryLock(): bool
    {
        if ($this->lockDepth > 0) {
            $this->lockDepth++;
            return true;
        }
        $handle = $this->openLock();
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->lockHandle = $handle;
        $this->lockDepth = 1;
        return true;
    }

    public function lock(): void
    {
        if ($this->lockDepth > 0) {
            $this->lockDepth++;
            return;
        }
        $handle = $this->openLock();
        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StoreException('Could not lock the data folder.');
        }
        $this->lockHandle = $handle;
        $this->lockDepth = 1;
    }

    public function unlock(): void
    {
        if ($this->lockDepth === 0) {
            return;
        }
        if (--$this->lockDepth === 0 && $this->lockHandle !== null) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /** Append one line to the history. Never throws: logging must not break the switch. */
    public function log(string $event, string $text, array $extra = []): void
    {
        $line = json_encode(
            ['at' => Clock::now(), 'event' => $event, 'text' => $text] + $extra,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($line === false || !is_dir($this->dir)) {
            return;
        }
        $file = $this->dir . '/history.jsonl';
        @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
        @chmod($file, 0600);
        // Keep the file small: when it passes 400 KB, keep the newest 1,000 lines.
        if (@filesize($file) > 400_000) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (is_array($lines)) {
                @file_put_contents($file, implode("\n", array_slice($lines, -1000)) . "\n", LOCK_EX);
            }
        }
    }

    /** @return array<int, array> newest first */
    public function history(int $limit = 200): array
    {
        $file = $this->dir . '/history.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach (array_reverse(array_slice($lines, -$limit)) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Names of files that had to be restored from backup during this request. */
    public function recoveredFiles(): array
    {
        return array_keys($this->recovered);
    }

    private function recover(string $name): array
    {
        $backup = self::decode(@file_get_contents($this->path($name) . '.bak'));
        if ($backup === null) {
            throw new StoreException(
                "{$name}.json in the data folder is damaged and there is no usable backup. "
                . 'The switch cannot run until this is repaired.'
            );
        }
        if (!isset($this->recovered[$name])) {
            $this->recovered[$name] = true;
            $this->log('store.recovered', "{$name}.json was unreadable. The last good copy was used instead.");
        }
        return $backup;
    }

    private static function decode(string|false|null $json): ?array
    {
        if (!is_string($json) || trim($json) === '') {
            return null;
        }
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /** @return resource */
    private function openLock()
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new StoreException("Cannot create the data folder at {$this->dir}.");
        }
        $handle = @fopen($this->dir . '/.lock', 'c');
        if ($handle === false) {
            throw new StoreException("Cannot write to the data folder at {$this->dir}.");
        }
        return $handle;
    }
}
