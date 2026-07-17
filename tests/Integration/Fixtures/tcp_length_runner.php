<?php

declare(strict_types=1);

/**
 * Parity harness — a STANDALONE TCP server using LENGTH framing (4-byte 'N'
 * big-endian body-length prefix). Exercises the length codec on the wire, which
 * today is only unit-tested at the config level (TcpServerConfigTest). SR-M3
 * rewrite target.
 *
 * Swoole's open_length_check delivers the COMPLETE packet (header + body) to the
 * receive callback, so the handler strips the 4-byte prefix to read the body and
 * re-frames its reply. Launched as a child process; argv[1] = TCP port.
 *
 * NOTE: Server::serve() is typed RequestHandlerInterface, so a PSR-15 stub is
 * present only to satisfy the signature — handle() is never wired (no HttpServer).
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Contract\TcpHandlerInterface;
use PHPdot\Server\Server;
use PHPdot\Server\Tcp\TcpFraming;
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
    fwrite(STDERR, "usage: tcp_length_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();
$tcpServer = new TcpServer(new TcpServerConfig(
    host: '127.0.0.1',
    port: $port,
    framing: TcpFraming::Length,
));

$handler = new class ($tcpServer, $factory) implements RequestHandlerInterface, TcpHandlerInterface {
    public function __construct(
        private readonly TcpServer $tcp,
        private readonly ResponseFactory $factory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->createResponse(200);
    }

    public function handleTcpConnect(int $fd): void {}

    public function handleTcpReceive(int $fd, string $data): void
    {
        $body = substr($data, 4);            // strip the 4-byte 'N' length header
        $reply = 'LEN: ' . $body;
        $this->tcp->send($fd, pack('N', strlen($reply)) . $reply);
    }

    public function handleTcpClose(int $fd): void {}
};

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));
$server->attach($tcpServer);
$server->serve($handler);
