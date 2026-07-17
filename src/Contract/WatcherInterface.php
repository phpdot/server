<?php

declare(strict_types=1);

/**
 * WatcherInterface.
 *
 * The policy for the development file watcher: which directories and extensions
 * to scan, how deep to recurse, what a changed file triggers, and the poll and
 * debounce timing. The watch engine is pure mechanism; this is pure policy, so
 * a developer takes full control simply by binding their own implementation.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use PHPdot\Server\Watch\WatchAction;

interface WatcherInterface
{
    /**
     * Absolute directories to scan. Use excludes() to skip subdirectories such
     * as vendor rather than relying on the caller to pre-filter.
     *
     * @return list<string>
     */
    public function paths(): array;

    /**
     * File extensions to include, without the leading dot, e.g. ['php'].
     *
     * @return list<string>
     */
    public function extensions(): array;

    /**
     * Glob patterns (matched against each entry's basename) to skip during the
     * scan, e.g. ['vendor', '.git', '*.log']. A matched directory is not recursed.
     *
     * @return list<string>
     */
    public function excludes(): array;

    /**
     * Subdirectory depth to descend: -1 = unlimited (recursive), 0 = flat
     * (the watched directory only), N = N levels deep.
     *
     * @return int
     */
    public function depth(): int;

    /**
     * Decide what a changed file triggers. This is where a developer's
     * watch rules live.
     *
     * @param string $path
     *
     * @return WatchAction
     */
    public function classify(string $path): WatchAction;

    /**
     * Poll cadence in seconds.
     *
     * @return float
     */
    public function interval(): float;

    /**
     * Debounce window in seconds — coalesce a burst of changes into one action.
     *
     * @return float
     */
    public function debounce(): float;
}
