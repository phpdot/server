<?php

declare(strict_types=1);

/**
 * TcpServer — the raw-TCP transport (attachable). Owns its bind address, socket
 * type, and wire framing. When primary (TCP-only), the master IS its socket (a
 * plain Swoole\Server); when attached alongside an HttpServer, it adds a port via
 * listen() and wires connect/receive/close onto it. Always applies framing — an
 * added TCP port on an Http/WebSocket master never fires `receive` without it
 * (verified Swoole 6.2.1).
 *
 * Connection events are delegated to a TcpHandlerInterface ($fd-based, no
 * reactorId). To push data to a connection, call send($fd, …); the broader
 * ConnectionRegistry service lands in M4.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Tcp;

use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Contract\TcpHandlerInterface;
use PHPdot\Server\Contract\Transport;
use PHPdot\Server\Exception\ServerException;
use Swoole\Server as SwooleServer;

final class TcpServer implements Transport
{
    private SwooleServer|null $master = null;

    /**
     * __construct.
     *
     * @param TcpServerConfig $config
     */
    public function __construct(
        private readonly TcpServerConfig $config = new TcpServerConfig(),
    ) {}

    public function host(): string
    {
        return $this->config->host;
    }

    public function port(): int
    {
        return $this->config->port;
    }

    public function sockType(): int
    {
        return $this->config->sockType;
    }

    /**
     * Framing settings (TcpServerConfig::toArray()). Merged into the master set()
     * when TcpServer is the primary; applied to the added Port via set() otherwise.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->config->toArray();
    }

    public function register(SwooleServer $master, bool $primary, object|null $handler): void
    {
        if (!$handler instanceof TcpHandlerInterface) {
            throw new ServerException('TcpServer requires a TcpHandlerInterface handler (pass the aggregate to Server::serve()).');
        }

        $this->master = $master;

        $connect = static function (SwooleServer $s, int $fd) use ($handler): void {
            $handler->handleTcpConnect($fd);
        };
        $receive = static function (SwooleServer $s, int $fd, int $reactorId, string $data) use ($handler): void {
            $handler->handleTcpReceive($fd, $data);
        };
        $close = static function (SwooleServer $s, int $fd) use ($handler): void {
            $handler->handleTcpClose($fd);
        };

        if ($primary) {
            $master->on('connect', $connect);
            $master->on('receive', $receive);
            $master->on('close', $close);

            return;
        }

        if ($this->config->framing === TcpFraming::None) {
            throw new ServerException(
                'A non-primary TcpServer requires framing (Eof or Length); TcpFraming::None never fires receive on an added port.',
            );
        }

        $port = $master->listen($this->config->host, $this->config->port, $this->config->sockType);
        if (!$port instanceof \Swoole\Server\Port) {
            throw new ServerException("TcpServer failed to listen on {$this->config->host}:{$this->config->port}.");
        }
        $port->set($this->config->toArray());
        $port->on('connect', $connect);
        $port->on('receive', $receive);
        $port->on('close', $close);
    }

    /**
     * Send data to a TCP connection by file descriptor. The fd is global to the
     * master, so this works whether TcpServer is primary or attached.
     *
     * @param int $fd
     * @param string $data
     *
     * @throws ServerException If register() has not run.
     *
     * @return bool
     */
    public function send(int $fd, string $data): bool
    {
        return $this->requireMaster()->send($fd, $data);
    }

    /**
     * Require master.
     *
     * @return SwooleServer
     */
    private function requireMaster(): SwooleServer
    {
        if ($this->master === null) {
            throw new ServerException('TcpServer has not been registered. Call Server::serve() first.');
        }

        return $this->master;
    }
}
