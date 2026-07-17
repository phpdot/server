<?php

declare(strict_types=1);

/**
 * Parity harness — a STANDALONE TCP server (no HttpServer attached), so the
 * TcpServer is the PRIMARY transport and the Swoole master is a plain
 * Swoole\Server. Exercises the primary-transport TCP path (TcpServer.php:82-89),
 * which has zero coverage today and is an SR-M3 rewrite target.
 *
 * Boots a Server with ONLY a TcpServer (default EOF framing, "\n") whose handler
 * echoes each framed line, counts connect/close events, and reports the counts on
 * a "STATS" line so the test can observe that connect/close handlers fire.
 * Launched as a separate process; argv[1] = TCP port.
 *
 * NOTE: Server::serve() is typed RequestHandlerInterface, so even a TCP-only
 * server needs a PSR-15 stub to satisfy the signature — handle() is never wired
 * here (no HttpServer). Flagged as an API wart for the SR-M3 rewrite.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Contract\TcpHandlerInterface;
use PHPdot\Server\Server;
use PHPdot\Server\Tcp\TcpServer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: tcp_standalone_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();
$tcpServer = new TcpServer(new TcpServerConfig(host: '127.0.0.1', port: $port));

$handler = new class ($tcpServer, $factory) implements RequestHandlerInterface, TcpHandlerInterface {
    private int $connects = 0;

    private int $closes = 0;

    public function __construct(
        private readonly TcpServer $tcp,
        private readonly ResponseFactory $factory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Never wired — no HttpServer attached. Present only to satisfy serve()'s type.
        return $this->factory->createResponse(200);
    }

    public function handleTcpConnect(int $fd): void
    {
        $this->connects++;
    }

    public function handleTcpReceive(int $fd, string $data): void
    {
        $message = trim($data);

        if ($message === 'STATS') {
            $this->tcp->send($fd, "connects={$this->connects};closes={$this->closes}\n");

            return;
        }

        $this->tcp->send($fd, 'ECHO: ' . $message . "\n");
    }

    public function handleTcpClose(int $fd): void
    {
        $this->closes++;
    }
};

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));
$server->attach($tcpServer);
$server->serve($handler);
