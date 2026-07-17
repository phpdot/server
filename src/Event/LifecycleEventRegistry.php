<?php

declare(strict_types=1);

/**
 * LifecycleEventRegistry — holds lifecycle listeners and wires them onto the
 * Swoole master, replacing server-swoole's 70-line subscribe() if-instanceof
 * chain. Each event gets ONE composite handler that fans out to every listener
 * implementing the matching interface (Swoole replaces a handler if on() is
 * called again, so we register a single multiplexer per event).
 *
 * Also installs graceful SIGINT handling (the master coordinates shutdown;
 * manager + workers ignore SIGINT so a rapid double Ctrl+C can't kill a child
 * out of order mid-teardown).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Event;

use PHPdot\Server\Contract\OnAfterReloadInterface;
use PHPdot\Server\Contract\OnBeforeReloadInterface;
use PHPdot\Server\Contract\OnBeforeShutdownInterface;
use PHPdot\Server\Contract\OnManagerStartInterface;
use PHPdot\Server\Contract\OnManagerStopInterface;
use PHPdot\Server\Contract\OnShutdownInterface;
use PHPdot\Server\Contract\OnStartInterface;
use PHPdot\Server\Contract\OnWorkerErrorInterface;
use PHPdot\Server\Contract\OnWorkerExitInterface;
use PHPdot\Server\Contract\OnWorkerStartInterface;
use PHPdot\Server\Contract\OnWorkerStopInterface;
use PHPdot\Server\Server;
use Swoole\Server as SwooleServer;

final class LifecycleEventRegistry
{
    /**
     * @var list<object>
     */
    private array $listeners = [];

    /**
     * The registered listeners for the given lifecycle event.
     *
     * @return list<object>
     */
    public function listeners(): array
    {
        return $this->listeners;
    }

    /**
     * Subscribe a lifecycle listener. Each On*Interface it implements is wired
     * onto the matching event by register(). Listeners stack — never replace.
     *
     * @param object $listener
     *
     * @return void
     */
    public function subscribe(object $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * Wire every lifecycle event onto the master. The master SIGINT coroutine
     * and the manager/worker SIGINT-ignore are always installed (graceful
     * shutdown is default behaviour); user listeners fan out inside the same
     * composites.
     *
     * @param SwooleServer $master
     * @param Server $server
     *
     * @return void
     */
    public function register(SwooleServer $master, Server $server): void
    {
        $listeners = $this->listeners;
        $isBase = $server->config()->mode === SWOOLE_BASE;

        $master->on('start', static function () use ($server, $listeners): void {
            \Swoole\Process::signal(SIGINT, static function () use ($server): void {
                $server->shutdown();
            });

            foreach ($listeners as $listener) {
                if ($listener instanceof OnStartInterface) {
                    $listener->onStart($server);
                }
            }
        });

        $master->on('managerStart', static function () use ($server, $listeners, $isBase): void {
            \Swoole\Process::signal(SIGINT, $isBase
                ? static function () use ($server): void {
                    $server->shutdown();
                }
                : static function (): void {});
            foreach ($listeners as $listener) {
                if ($listener instanceof OnManagerStartInterface) {
                    $listener->onManagerStart($server);
                }
            }
        });

        $master->on('managerStop', static function () use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnManagerStopInterface) {
                    $listener->onManagerStop($server);
                }
            }
        });

        $master->on('workerStart', static function (SwooleServer $s, int $workerId) use ($server, $listeners, $isBase): void {
            if (!($isBase && $workerId === 0)) {
                \Swoole\Process::signal(SIGINT, static function (): void {});
            }
            foreach ($listeners as $listener) {
                if ($listener instanceof OnWorkerStartInterface) {
                    $listener->onWorkerStart($server, $workerId);
                }
            }
        });

        $master->on('workerStop', static function (SwooleServer $s, int $workerId) use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnWorkerStopInterface) {
                    $listener->onWorkerStop($server, $workerId);
                }
            }
        });

        $master->on('workerExit', function (SwooleServer $s, int $workerId) use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnWorkerExitInterface) {
                    $listener->onWorkerExit($server, $workerId);
                }
            }

            /**
             * @var float $drainStartedAt
             */
            static $drainStartedAt = 0.0;

            /**
             * @var bool $reported
             */
            static $reported = false;

            if ($drainStartedAt === 0.0) {
                $drainStartedAt = microtime(true);
            }

            if (!$reported && microtime(true) - $drainStartedAt >= 1.0) {
                $reported = $this->reportDrainPins($workerId);
            }
        });

        $master->on('workerError', static function (SwooleServer $s, int $workerId, int $workerPid, int $exitCode, int $signal) use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnWorkerErrorInterface) {
                    $listener->onWorkerError($server, $workerId, $workerPid, $exitCode, $signal);
                }
            }
        });

        $master->on('beforeShutdown', static function () use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnBeforeShutdownInterface) {
                    $listener->onBeforeShutdown($server);
                }
            }
        });

        $master->on('shutdown', static function () use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnShutdownInterface) {
                    $listener->onShutdown($server);
                }
            }
        });

        $master->on('beforeReload', static function () use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnBeforeReloadInterface) {
                    $listener->onBeforeReload($server);
                }
            }
        });

        $master->on('afterReload', static function () use ($server, $listeners): void {
            foreach ($listeners as $listener) {
                if ($listener instanceof OnAfterReloadInterface) {
                    $listener->onAfterReload($server);
                }
            }
        });
    }

    /**
     * Name whatever still pins a draining worker — each coroutine by its top
     * frame, plus the live timer count — so a slow shutdown surfaces as a
     * diagnosis instead of a bare ERRNO 9101 force-kill. Returns true when
     * something was reported. No Timer::clearAll() backstop belongs here:
     * in-flight work wakes via timers (hooked sleep, IO timeouts), and
     * clearing them strands that work mid-request.
     *
     * @param int $workerId
     *
     * @return bool
     */
    private function reportDrainPins(int $workerId): bool
    {
        $self = \Swoole\Coroutine::getCid();
        $pinned = [];

        foreach (\Swoole\Coroutine::list() as $cid) {
            if (!is_int($cid) || $cid === $self) {
                continue;
            }

            $trace = \Swoole\Coroutine::getBackTrace($cid, DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            $frame = is_array($trace) && isset($trace[0]) && is_array($trace[0]) ? $trace[0] : [];
            $class = is_string($frame['class'] ?? null) ? $frame['class'] . '::' : '';
            $function = is_string($frame['function'] ?? null) ? $frame['function'] : '?';
            $pinned[] = "#{$cid} {$class}{$function}";
        }

        $stats = \Swoole\Timer::stats();
        $timerCount = is_int($stats['num'] ?? null) ? $stats['num'] : 0;

        if ($pinned === [] && $timerCount === 0) {
            return false;
        }

        fwrite(STDERR, sprintf(
            "[server] worker %d draining: %d coroutine(s) still running (%s), %d timer(s) still registered — "
            . "waiting up to max_wait_time for them to finish; only if they don't, the worker is force-killed (ERRNO 9101)\n",
            $workerId,
            count($pinned),
            $pinned === [] ? 'none' : implode(', ', $pinned),
            $timerCount,
        ));

        return true;
    }
}
