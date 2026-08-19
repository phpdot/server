<?php

declare(strict_types=1);

/**
 * WatchConfig — development file-watching settings for server:start --watch.
 * The flag decides WHETHER to watch; this config decides WHAT: which paths
 * (directories scanned recursively, listed files watched as-is), which
 * extensions, what to skip, and which path segments demand a full restart
 * because they load before the fork (config, routes, DI definitions).
 *
 * Auto-bound by phpdot/config when phpdot/package is installed: the user edits
 * config/server/watch.php; the DTO is hydrated from that file.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Config;

use PHPdot\Container\Attribute\Config;
use PHPdot\Server\Watch\Watcher;

#[Config('server.watch')]
final class WatchConfig
{
    /**
     * Create the watch configuration.
     *
     * @param list<string> $paths Directories to scan and/or files to watch; empty = the
     *                            current working directory
     * @param list<string> $extensions Extensions to include without the dot; empty = ['php']
     * @param list<string> $excludes fnmatch() globs matched against each path segment;
     *                               empty = ['vendor', '.git']
     * @param list<string> $restart Path segments whose changes need a full restart because
     *                              they load before the fork, e.g. ['config', 'routes']
     * @param int $depth Directory recursion depth (-1 = unlimited)
     * @param float $interval Seconds between poll sweeps
     * @param float $debounce Seconds to let a burst of edits settle before acting
     */
    public function __construct(
        public readonly array $paths = [],
        public readonly array $extensions = [],
        public readonly array $excludes = [],
        public readonly array $restart = [],
        public readonly int $depth = -1,
        public readonly float $interval = 1.0,
        public readonly float $debounce = 0.25,
    ) {}

    /**
     * Build the Watcher this configuration describes.
     *
     * @return Watcher
     */
    public function toWatcher(): Watcher
    {
        return new Watcher(
            paths: $this->paths,
            extensions: $this->extensions,
            excludes: $this->excludes,
            restart: $this->restart,
            depth: $this->depth,
            interval: $this->interval,
            debounce: $this->debounce,
        );
    }
}
