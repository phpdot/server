<?php

declare(strict_types=1);

/**
 * Watcher.
 *
 * A standalone, configurable WatcherInterface built from explicit values —
 * typically the `--watch` flags. It assumes nothing about any framework layout:
 * with no paths it watches the current working directory, skips vendor and .git,
 * and includes .php. Every change is a worker reload except files under one of
 * the restart prefixes, which load before the fork and need a full restart.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Watch;

use PHPdot\Server\Contract\WatcherInterface;

final class Watcher implements WatcherInterface
{
    /**
     * Create the watcher over its file watcher and reload strategy.
     *
     * @param list<string> $paths Directories to scan; empty = the current working directory
     * @param list<string> $extensions Extensions to include without the dot; empty = ['php']
     * @param list<string> $excludes Globs to skip during the scan; empty = ['vendor', '.git']
     * @param list<string> $restart Path segments that require a full restart, e.g. ['config']
     * @param int $depth
     * @param float $interval
     * @param float $debounce
     */
    public function __construct(
        private readonly array $paths = [],
        private readonly array $extensions = [],
        private readonly array $excludes = [],
        private readonly array $restart = [],
        private readonly int $depth = -1,
        private readonly float $interval = 1.0,
        private readonly float $debounce = 0.25,
    ) {}

    public function paths(): array
    {
        if ($this->paths !== []) {
            return $this->paths;
        }

        $cwd = getcwd();

        return [$cwd !== false ? $cwd : '.'];
    }

    public function extensions(): array
    {
        return $this->extensions !== [] ? $this->extensions : ['php'];
    }

    public function excludes(): array
    {
        return $this->excludes !== [] ? $this->excludes : ['vendor', '.git'];
    }

    public function depth(): int
    {
        return $this->depth;
    }

    public function classify(string $path): WatchAction
    {
        $haystack = $path . DIRECTORY_SEPARATOR;

        foreach ($this->restart as $segment) {
            $needle = DIRECTORY_SEPARATOR . trim($segment, '/\\') . DIRECTORY_SEPARATOR;

            if (str_contains($haystack, $needle)) {
                return WatchAction::Restart;
            }
        }

        return WatchAction::Reload;
    }

    public function interval(): float
    {
        return $this->interval;
    }

    public function debounce(): float
    {
        return $this->debounce;
    }
}
