<?php

declare(strict_types=1);

/**
 * FileWatcher.
 *
 * The development hot-reload engine for LIBRARY embedding: runs as a Swoole
 * user process (attached by the ProcessManager), polling the paths described
 * by a WatcherInterface — directories are scanned recursively, explicitly
 * listed files are watched as-is. App-code changes reload the workers
 * (SIGUSR1 — reloads code loaded after the fork); restart-classified changes
 * (pre-fork state) print a notice, because a process cannot restart itself.
 * The CLI's --watch does NOT use this class in-server: its supervisor watches
 * the files itself and signals the child pid it owns, which also covers full
 * restarts. snapshot()/plan() are reused by that supervisor. All policy lives
 * in WatcherInterface; this is pure mechanism.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Watch;

use PHPdot\Server\Contract\WatcherInterface;
use Swoole\Coroutine;
use Swoole\Process;
use Swoole\Server as SwooleServer;

final class FileWatcher
{
    private bool $running = true;

    /**
     * __construct.
     *
     * @param WatcherInterface $watcher
     */
    public function __construct(
        private readonly WatcherInterface $watcher,
    ) {}

    /**
     * Run the poll loop against the running master. Blocks until stop() is called
     * or — the normal case — the Swoole master terminates this user process on
     * server shutdown.
     *
     * @param SwooleServer $master
     * @param int $masterPid The master's pid captured pre-fork by ProcessManager::attachTo()
     *                       — the authoritative value; 0 falls back to runtime resolution
     *
     * @return void
     */
    public function run(SwooleServer $master, int $masterPid = 0): void
    {
        Process::signal(SIGINT, static function (): void {});
        Process::signal(SIGTERM, function (): void {
            $this->stop();
        });

        if ($masterPid <= 0) {
            $masterPid = $this->resolveMasterPid($master);
        }
        $previous = $this->snapshot();

        while ($this->running) {
            Coroutine::sleep($this->watcher->interval());

            if ($masterPid > 0 && Process::kill($masterPid, 0) === false) {
                break;
            }

            $plan = $this->plan($previous);

            if ($plan['reload'] === [] && $plan['restart'] === []) {
                $previous = $plan['snapshot'];

                continue;
            }

            Coroutine::sleep($this->watcher->debounce());
            $plan = $this->plan($previous);

            $this->act($masterPid, $plan['reload'], $plan['restart']);
            $previous = $plan['snapshot'];
        }
    }

    /**
     * The master's pid, resolved robustly from inside the forked user process:
     * getMasterPid() returns 0 in BASE mode here (signalling pid 0 would hit
     * the whole process group), so fall back to the master_pid property and
     * finally to this process's parent.
     *
     * @param SwooleServer $master
     *
     * @return int
     */
    private function resolveMasterPid(SwooleServer $master): int
    {
        $pid = $master->getMasterPid();

        if ($pid <= 0) {
            $pid = $master->master_pid;
        }

        if ($pid <= 0 && function_exists('posix_getppid')) {
            $pid = posix_getppid();
        }

        return $pid;
    }

    /**
     * Stop the poll loop after the current iteration.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->running = false;
    }

    /**
     * Current snapshot: absolute file path => change signature (mtime + size, so
     * a same-second edit that also changes the size is still detected).
     *
     * @return array<string, string>
     */
    public function snapshot(): array
    {
        clearstatcache();

        $files = [];

        foreach ($this->watcher->paths() as $root) {
            if (is_file($root)) {
                $this->record($root, $files);

                continue;
            }

            $this->scan($root, 0, $files);
        }

        return $files;
    }

    /**
     * Diff a previous snapshot against the current one and classify each change.
     *
     * @param array<string, string> $previous
     *
     * @return array{reload: list<string>, restart: list<string>, snapshot: array<string, string>}
     */
    public function plan(array $previous): array
    {
        $snapshot = $this->snapshot();
        $reload = [];
        $restart = [];

        foreach ($this->changed($previous, $snapshot) as $path) {
            match ($this->watcher->classify($path)) {
                WatchAction::Reload => $reload[] = $path,
                WatchAction::Restart => $restart[] = $path,
                WatchAction::Ignore => null,
            };
        }

        return ['reload' => $reload, 'restart' => $restart, 'snapshot' => $snapshot];
    }

    /**
     * Whether any watched file changed since the last snapshot.
     *
     * @param array<string, string> $previous
     * @param array<string, string> $current
     *
     * @return list<string>
     */
    private function changed(array $previous, array $current): array
    {
        $changed = [];

        foreach ($current as $path => $signature) {
            if (($previous[$path] ?? null) !== $signature) {
                $changed[] = $path;
            }
        }

        foreach ($previous as $path => $signature) {
            if (!isset($current[$path])) {
                $changed[] = $path;
            }
        }

        return $changed;
    }

    /**
     * Snapshot the modification times of every watched path.
     *
     * @param array<string, string> $files
     * @param string $dir
     * @param int $level
     *
     * @return void
     */
    private function scan(string $dir, int $level, array &$files): void
    {
        $handle = @opendir($dir);

        if ($handle === false) {
            return;
        }

        $extensions = $this->watcher->extensions();
        $excludes = $this->watcher->excludes();
        $depth = $this->watcher->depth();

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..' || $this->excluded($entry, $excludes)) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                if (!is_link($path) && ($depth === -1 || $level < $depth)) {
                    $this->scan($path, $level + 1, $files);
                }

                continue;
            }

            if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, true)) {
                continue;
            }

            $this->record($path, $files);
        }

        closedir($handle);
    }

    /**
     * Record a file's change signature (mtime:size:inode) into the snapshot.
     * The inode catches atomic-rename saves — how most editors write — even
     * when mtime's one-second resolution and an unchanged size would hide the
     * edit; only an in-place same-second write of identical length remains
     * invisible.
     *
     * @param string $path Absolute file path
     * @param array<string, string> $files
     *
     * @return void
     */
    private function record(string $path, array &$files): void
    {
        $mtime = @filemtime($path);
        $size = @filesize($path);
        $inode = @fileinode($path);

        if ($mtime !== false && $size !== false) {
            $files[$path] = $mtime . ':' . $size . ':' . ($inode === false ? 0 : $inode);
        }
    }

    /**
     * Whether the path matches an exclusion pattern.
     *
     * @param list<string> $excludes
     * @param string $name
     *
     * @return bool
     */
    private function excluded(string $name, array $excludes): bool
    {
        foreach ($excludes as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * React to detected changes: reload workers or restart the server. Restart
     * supersedes reload — a mixed batch drains and re-execs, which reloads
     * everything anyway.
     *
     * @param list<string> $reload
     * @param list<string> $restart
     * @param int $masterPid
     *
     * @return void
     */
    private function act(int $masterPid, array $reload, array $restart): void
    {
        if ($masterPid <= 0) {
            return;
        }

        if ($restart !== []) {
            $this->notice('restart required', $restart);
        }

        if ($reload !== []) {
            $this->notice('reloaded', $reload);
            Process::kill($masterPid, SIGUSR1);
        }
    }

    /**
     * Print a console notice listing the watched paths.
     *
     * @param list<string> $paths
     * @param string $label
     *
     * @return void
     */
    private function notice(string $label, array $paths): void
    {
        $cwd = getcwd();
        $relative = array_map(
            static function (string $path) use ($cwd): string {
                if ($cwd !== false && str_starts_with($path, $cwd)) {
                    return ltrim(substr($path, strlen($cwd)), DIRECTORY_SEPARATOR);
                }

                return $path;
            },
            $paths,
        );

        echo sprintf("[watch] %s: %s\n", $label, implode(', ', $relative));
    }
}
