<?php

declare(strict_types=1);

/**
 * A transport the Server runner can attach(). A transport owns its bind address,
 * its port-level settings, and the knowledge of how to wire its events onto the
 * shared Swoole master. It never owns the process and never calls start().
 *
 * When the runner calls register() with $primary = true, the master's main port
 * IS this transport's port — wire events directly onto $master. With
 * $primary = false, add a port via $master->listen() and wire onto the Port.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

use Swoole\Server as SwooleServer;

interface Transport
{
    /**
     * Bind host.
     *
     * @return string
     */
    public function host(): string;

    /**
     * Bind port.
     *
     * @return int
     */
    public function port(): int;

    /**
     * Swoole socket type (SWOOLE_SOCK_TCP, …), optionally OR-ed with SWOOLE_SSL.
     *
     * @return int
     */
    public function sockType(): int;

    /**
     * Port-level settings this transport contributes when it is the primary
     * (HTTP toggles for HttpServer, framing for TcpServer), merged on top of the
     * runner's server-wide settings during set(). Empty when not primary.
     *
     * @return array<string, mixed>
     */
    public function settings(): array;

    /**
     * Wire this transport onto the shared master. MUST run before start() —
     * Swoole forbids listen() after start() (verified 6.2.1).
     *
     * @param SwooleServer $master the runner's master.
     * @param bool $primary true if this transport owns the main port.
     * @param object|null $handler the application handler (PSR-15 + protocol handlers).
     *
     * @return void
     */
    public function register(SwooleServer $master, bool $primary, object|null $handler): void;
}
