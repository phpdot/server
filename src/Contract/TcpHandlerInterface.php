<?php

declare(strict_types=1);

/**
 * Handles raw TCP connection lifecycle.
 *
 * Implemented by the application's handler aggregate (alongside PSR-15
 * RequestHandlerInterface and, optionally, WebSocketHandlerInterface /
 * SseHandlerInterface). The server's TcpServer transport detects this interface
 * on the handler passed to Server::serve() and delegates TCP connection events.
 *
 * Connections are identified by their Swoole file descriptor ($fd) — the same
 * $fd-based style as WebSocketHandlerInterface. To push data to a connection,
 * resolve the ConnectionRegistry service (M4) and call send($fd, $data); for M2,
 * TcpServer exposes a minimal send($fd, $data) itself. A higher-level
 * TcpConnection wrapper may be added later for controllers, but this contract
 * stays $fd-based. $reactorId is intentionally absent (a Swoole internal).
 *
 * Lives in the server package during development; extracts to phpdot/contracts
 * (PHPdot\Contracts\Server\TcpHandlerInterface) at publish time alongside the
 * other Server contracts.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Contract;

interface TcpHandlerInterface
{
    /**
     * Handle a new TCP connection.
     *
     * @param int $fd Connection file descriptor
     *
     * @return void
     */
    public function handleTcpConnect(int $fd): void;

    /**
     * Handle incoming data on a TCP connection.
     *
     * @param int $fd Connection file descriptor
     * @param string $data Received payload, framed per the TcpServer's framing mode
     *
     * @return void
     */
    public function handleTcpReceive(int $fd, string $data): void;

    /**
     * Handle a TCP connection closing.
     *
     * @param int $fd Connection file descriptor
     *
     * @return void
     */
    public function handleTcpClose(int $fd): void;
}
