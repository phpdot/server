<?php

declare(strict_types=1);

/**
 * M3 WebSocket test runner. Boots a Server with an HttpServer whose aggregate
 * handler speaks PSR-15 + WebSocketHandlerInterface. Text frames echo back as
 * "echo:<data>"; binary frames (opcode 0x2) echo back raw as a binary frame.
 * Proves the WS upgrade + message round-trips on the WebSocket\Server master.
 * Launched as a child process by WebSocketTest; argv[1] = port.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Contracts\Server\WebSocketHandlerInterface;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;
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
    fwrite(STDERR, "usage: server_ws_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface, WebSocketHandlerInterface {
    /** @var array<int, Closure(string): bool> */
    private array $senders = [];

    /** @var array<int, Closure(string): bool> */
    private array $binarySenders = [];

    public function __construct(private readonly ResponseFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('OK'));
    }

    public function handleWsOpen(
        int $fd,
        ServerRequestInterface $request,
        Closure $send,
        Closure $sendBinary,
        Closure $close,
    ): bool {
        $this->senders[$fd] = $send;
        $this->binarySenders[$fd] = $sendBinary;

        return true;
    }

    public function handleWsMessage(int $fd, string $data, int $opcode): void
    {
        if ($opcode === WEBSOCKET_OPCODE_BINARY) {
            ($this->binarySenders[$fd])($data);

            return;
        }

        ($this->senders[$fd])('echo:' . $data);
    }

    public function handleWsClose(int $fd, int $code, string $reason): void
    {
        unset($this->senders[$fd], $this->binarySenders[$fd]);
    }
};

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));
$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));
$server->serve($handler);
