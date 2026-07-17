<?php

declare(strict_types=1);

/**
 * Timer — the typed entry point over Swoole\Timer.
 *
 * Timers are process-global (Swoole\Timer), not scoped to a server instance, so
 * this service holds no master reference — it wraps the static scheduler. Kept
 * as a service for a single injectable, mockable time surface.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Timer;

use Closure;
use PHPdot\Container\Attribute\Singleton;
use Swoole\Timer as SwooleTimer;

#[Singleton]
final class Timer
{
    /**
     * Register a recurring timer firing every $ms milliseconds.
     *
     * @param int $ms Interval in milliseconds
     * @param Closure(int): void $callback Receives the timer ID each tick
     *
     * @return int|false Timer ID, or false on failure
     */
    public function tick(int $ms, Closure $callback): int|false
    {
        return SwooleTimer::tick($ms, $callback);
    }

    /**
     * Register a one-shot timer firing once after $ms milliseconds.
     *
     * @param int $ms Delay in milliseconds
     * @param Closure(): void $callback
     *
     * @return int|false Timer ID, or false on failure
     */
    public function after(int $ms, Closure $callback): int|false
    {
        return SwooleTimer::after($ms, $callback);
    }

    /**
     * Clear.
     *
     * @param int $timerId
     *
     * @return bool
     */
    public function clear(int $timerId): bool
    {
        return SwooleTimer::clear($timerId);
    }
}
