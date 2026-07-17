<?php

declare(strict_types=1);

/**
 * ConnectionRegistry behaviour runner. Boots a Server with a WS-capable
 * HttpServer (primary) plus a TcpServer port, sharing one master, and logs
 * every handleWsOpen/handleWsClose to stdout so tests can assert exactly which
 * connections reach the WS close callback.
 *
 *   GET /broadcast       -> ConnectionRegistry::broadcast("BCAST-ALL\n")
 *   GET /broadcast-port  -> ConnectionRegistry::broadcast("BCAST-PORT\n", tcpPort)
 *   WS text "kick"       -> ConnectionRegistry::disconnect(fd) (server-initiated close)
 *   WS text other        -> echoed back as "echo:<data>"
 *   TCP framed line      -> echoed back as "ECHO: <line>"
 *
 * Launched as a child process by WsRegistryBehaviourTest;
 * argv[1] = HTTP port, argv[2] = TCP port.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Contracts\Server\WebSocketHandlerInterface;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Connection\ConnectionRegistry;
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
    fwrite(STDERR, "usage: server_ws_registry_runner.php <httpPort> <tcpPort>\n");
    exit(1);
}

$factory = new ResponseFactory();
$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));
$registry = new ConnectionRegistry($server);
$tcpServer = new TcpServer(new TcpServerConfig(host: '127.0.0.1', port: $tcpPort));

$handler = new class ($factory, $registry, $tcpServer, $tcpPort) implements RequestHandlerInterface, WebSocketHandlerInterface, TcpHandlerInterface {
    /** @var array<int, Closure(string): bool> */
    private array $senders = [];

    public function __construct(
        private readonly ResponseFactory $factory,
        private readonly ConnectionRegistry $registry,
        private readonly TcpServer $tcp,
        private readonly int $tcpPort,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($path === '/broadcast') {
            $this->registry->broadcast("BCAST-ALL\n");

            return $this->text('sent-all');
        }

        if ($path === '/broadcast-port') {
            $this->registry->broadcast("BCAST-PORT\n", $this->tcpPort);

            return $this->text('sent-port');
        }

        if ($path === '/disconnect-fd') {
            parse_str($request->getUri()->getQuery(), $q);
            $result = $this->registry->disconnect((int) ($q['fd'] ?? 0), 4000, 'swept');

            return $this->text($result ? 'true' : 'false');
        }

        return $this->text('OK');
    }

    public function handleWsOpen(int $fd, ServerRequestInterface $request, Closure $send, Closure $sendBinary, Closure $close): bool
    {
        $this->senders[$fd] = $send;
        echo "WSOPEN {$fd}\n";

        return true;
    }

    public function handleWsMessage(int $fd, string $data, int $opcode): void
    {
        if ($data === 'kick') {
            $this->registry->disconnect($fd, 4001, 'kicked');

            return;
        }

        if (isset($this->senders[$fd])) {
            ($this->senders[$fd])('echo:' . $data);
        }
    }

    public function handleWsClose(int $fd, int $code, string $reason): void
    {
        unset($this->senders[$fd]);
        echo "WSCLOSE {$fd}\n";
    }

    public function handleTcpConnect(int $fd): void {}

    public function handleTcpReceive(int $fd, string $data): void
    {
        $this->tcp->send($fd, 'ECHO: ' . trim($data) . "\n");
    }

    public function handleTcpClose(int $fd): void {}

    private function text(string $body): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream($body));
    }
};

$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $httpPort)));
$server->attach($tcpServer);
$server->serve($handler);
