<?php

declare(strict_types=1);

/**
 * ConnectionRegistry — server-wide connection operations by file descriptor.
 *
 * The app-facing send path (TcpServer keeps its own send() for transport-level
 * parity, but this is the canonical one — broadcast, info, existence, close).
 * Implements ConnectionSenderInterface — the outbound seam a real-time layer
 * (phpdot/realtime) depends on to reach clients without naming a concrete server.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Connection;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Server\ConnectionSenderInterface;
use PHPdot\Server\Server;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Server as WebSocketServer;

#[Singleton]
final class ConnectionRegistry implements ConnectionSenderInterface
{
    /**
     * __construct.
     *
     * @param Server $server
     */
    public function __construct(
        private readonly Server $server,
    ) {}

    /**
     * Send.
     *
     * @param int $fd
     * @param string $data
     *
     * @return bool
     */
    public function send(int $fd, string $data): bool
    {
        return $this->master()->send($fd, $data);
    }

    /**
     * Close.
     *
     * @param int $fd
     * @param bool $reset
     *
     * @return bool
     */
    public function close(int $fd, bool $reset = false): bool
    {
        return $this->master()->close($fd, $reset);
    }

    public function exists(int $fd): bool
    {
        return $this->master()->exists($fd);
    }

    /**
     * Swoole client info for the connection, or false when unknown.
     *
     * @param int $fd
     *
     * @return array<mixed, mixed>|false Connection info, or false if unknown
     */
    public function info(int $fd): array|false
    {
        return $this->master()->getClientInfo($fd);
    }

    /**
     * A page of connected file descriptors, or false.
     *
     * @param int $startFd
     * @param int $count
     *
     * @return array<mixed, mixed>|false List of file descriptors, or false
     */
    public function list(int $startFd = 0, int $count = 10): array|false
    {
        return $this->master()->getClientList($startFd, $count);
    }

    /**
     * Send $data to every currently-connected client on raw-TCP ports.
     *
     * The master's connections iterator spans EVERY listening port (verified in
     * swoole-src), and raw bytes written into an HTTP or WebSocket stream corrupt
     * that protocol mid-flight — so HTTP-transport ports and WS-touched fds are
     * always skipped (use broadcastWs() for WS delivery). Pass $port to limit
     * the broadcast to one listening port, e.g. a specific TcpServer's.
     *
     * @param string $data
     * @param ?int $port
     *
     * @return void
     */
    public function broadcast(string $data, int|null $port = null): void
    {
        $master = $this->master();
        $httpPorts = $this->server->httpPorts();

        foreach ($master->connections as $fd) {
            if (!is_int($fd)) {
                continue;
            }

            $info = $master->getClientInfo($fd);
            if (!is_array($info)) {
                continue;
            }

            $serverPort = $info['server_port'] ?? null;
            if ($port !== null && $serverPort !== $port) {
                continue;
            }
            if ($port === null && in_array($serverPort, $httpPorts, true)) {
                continue;
            }
            if (($info['websocket_status'] ?? 0) !== 0) {
                continue;
            }

            $master->send($fd, $data);
        }
    }

    /**
     * Push $data to every established WebSocket connection — a server-wide WS
     * broadcast (in SWOOLE_PROCESS the master routes each push, so this reaches
     * clients in every worker). No-op if the master isn't a WebSocket server.
     *
     * @param string $data
     *
     * @return void
     */
    public function broadcastWs(string $data): void
    {
        $master = $this->master();

        if (!$master instanceof WebSocketServer) {
            return;
        }

        foreach ($master->connections as $fd) {
            if (is_int($fd) && $master->isEstablished($fd)) {
                $master->push($fd, $data);
            }
        }
    }

    /**
     * Push a WebSocket frame to one established client (ConnectionSenderInterface).
     * The seam phpdot/realtime's adapter uses to deliver frames.
     */
    public function pushWs(int $fd, string $frame): bool
    {
        $master = $this->master();

        if (!$master instanceof WebSocketServer || !$master->isEstablished($fd)) {
            return false;
        }

        return $master->push($fd, $frame);
    }

    /**
     * Send a WebSocket PING control frame (ConnectionSenderInterface). Heartbeat probe;
     * a live client's WS stack auto-replies with a PONG (delivered to the message handler
     * when open_websocket_pong_frame is on), refreshing its last-seen time.
     */
    public function pingWs(int $fd): bool
    {
        $master = $this->master();

        if (!$master instanceof WebSocketServer || !$master->isEstablished($fd)) {
            return false;
        }

        return $master->push($fd, '', WEBSOCKET_OPCODE_PING);
    }

    /**
     * Gracefully close a connection (ConnectionSenderInterface). Uses the WebSocket
     * close handshake (code + reason) for established WS sockets, a plain close
     * otherwise. Safe on dead or non-WS fds — returns false instead of warning,
     * so bulk sweeps (heartbeat reap, auth sweep) stay silent after mass drops.
     */
    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        $master = $this->master();

        if ($master instanceof WebSocketServer) {
            return $master->isEstablished($fd) && $master->disconnect($fd, $code, $reason);
        }

        return $master->exists($fd) && $master->close($fd);
    }

    /**
     * Master.
     *
     * @return SwooleServer
     */
    private function master(): SwooleServer
    {
        return $this->server->getMaster();
    }
}
