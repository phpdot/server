<?php

declare(strict_types=1);

/**
 * FileWatcher.
 *
 * The development hot-reload engine. Runs as a Swoole user process (attached by
 * the ProcessManager), polling the files described by a WatcherInterface. On
 * change it reloads the workers for app code — SIGUSR1 to the master, which only
 * reloads code loaded after the fork — and prints a notice that a full restart
 * is required for code loaded before the fork (config, bootstrap). All policy
 * lives in WatcherInterface; this is pure mechanism.
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
     *
     * @return void
     */
    public function run(SwooleServer $master): void
    {
        Process::signal(SIGINT, static function (): void {});

        $masterPid = $master->getMasterPid();
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

            $this->act($master, $plan['reload'], $plan['restart']);
            $previous = $plan['snapshot'];
        }
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

            $mtime = @filemtime($path);
            $size = @filesize($path);

            if ($mtime !== false && $size !== false) {
                $files[$path] = $mtime . ':' . $size;
            }
        }

        closedir($handle);
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
     * React to detected changes: reload workers or restart the server.
     *
     * @param list<string> $reload
     * @param list<string> $restart
     * @param \Swoole\Server $master
     *
     * @return void
     */
    private function act(SwooleServer $master, array $reload, array $restart): void
    {
        if ($reload !== []) {
            $this->notice('reloaded', $reload);
            Process::kill($master->getMasterPid(), SIGUSR1);
        }

        if ($restart !== []) {
            $this->notice('restart required', $restart);
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
