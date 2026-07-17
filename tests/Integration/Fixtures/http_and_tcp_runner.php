<?php

declare(strict_types=1);

/**
 * M2 integration-test server runner.
 *
 * Boots a REAL Server with BOTH an HttpServer (primary) and a TcpServer (added
 * port) on one process, served by a single aggregate handler that is PSR-15 for
 * HTTP and TcpHandlerInterface for TCP (echoes each framed line). Proves HTTP and
 * raw TCP coexist on one master via attach(). Launched as a separate process by
 * HttpAndTcpTest; argv[1] = HTTP port, argv[2] = TCP port.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Contract\TcpHandlerInterface;
use PHPdot\Server\Http\HttpServer;
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

$httpPort = (int) ($argv[1] ?? 0);
$tcpPort = (int) ($argv[2] ?? 0);
if ($httpPort <= 0 || $tcpPort <= 0) {
    fwrite(STDERR, "usage: http_and_tcp_runner.php <httpPort> <tcpPort>\n");
    exit(1);
}

$factory = new ResponseFactory();

$tcpServer = new TcpServer(new TcpServerConfig(host: '127.0.0.1', port: $tcpPort));

$handler = new class ($tcpServer, $factory) implements RequestHandlerInterface, TcpHandlerInterface {
    public function __construct(
        private readonly TcpServer $tcp,
        private readonly ResponseFactory $factory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('OK'));
    }

    public function handleTcpConnect(int $fd): void {}

    public function handleTcpReceive(int $fd, string $data): void
    {
        $this->tcp->send($fd, 'ECHO: ' . trim($data) . "\n");
    }

    public function handleTcpClose(int $fd): void {}
};

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));
$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $httpPort)));
$server->attach($tcpServer);
$server->serve($handler);
