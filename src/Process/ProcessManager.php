<?php

declare(strict_types=1);

/**
 * ProcessManager — queues Swoole user processes (and the dev file-watcher) and
 * attaches them to the master before start().
 *
 * Stateless of the Server itself (collects handlers; the runner calls attachTo()
 * during serve()). add()/watch() must be called before serve().
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Process;

use Closure;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Server\Contract\WatcherInterface;
use PHPdot\Server\Watch\FileWatcher;
use Swoole\Process;
use Swoole\Server as SwooleServer;

#[Singleton]
final class ProcessManager
{
    /**
     * @var list<Closure(\Swoole\Process): void>
     */
    private array $processHandlers = [];

    /**
     * @var list<FileWatcher>
     */
    private array $fileWatchers = [];

    /**
     * Register a Swoole user process. Runs as its own OS process, managed by the
     * Swoole master (auto-restart on crash). Must be called before serve().
     *
     * @param Closure(\Swoole\Process): void $handler
     *
     * @return void
     */
    public function add(Closure $handler): void
    {
        $this->processHandlers[] = $handler;
    }

    /**
     * Attach the development file-watcher as a user process. Reloads workers on
     * app-code changes; prints a restart notice for code loaded before the fork.
     * Development only — never in production. Must be called before serve().
     *
     * @param WatcherInterface $watcher
     *
     * @return void
     */
    public function watch(WatcherInterface $watcher): void
    {
        $this->fileWatchers[] = new FileWatcher($watcher);
    }

    /**
     * Attach every queued process/watcher to the master. Called by the Server
     * runner during serve(), after master creation and before start().
     *
     * @param SwooleServer $master
     *
     * @return void
     */
    public function attachTo(SwooleServer $master): void
    {
        foreach ($this->processHandlers as $handler) {
            $master->addProcess(new Process($handler, false, 0, true));
        }

        foreach ($this->fileWatchers as $fileWatcher) {
            $master->addProcess(new Process(static function () use ($fileWatcher, $master): void {
                $fileWatcher->run($master);
            }, false, 0, true));
        }
    }
}
