<?php

declare(strict_types=1);

/**
 * Loaded-drain runner. The production shape under load: two workers,
 * SWOOLE_HOOK_ALL, the streaming settings engaged (SSE branch answers), the
 * cluster Heartbeat ticking against the compose Redis, and a WebSocket
 * channel — everything that held the 30s drain open before the fixes, live
 * at once. LoadedDrainTest SIGTERMs mid-flight and asserts in-flight work
 * completes AND the process exits far inside the raised 30s window.
 *
 * argv[1] = port, argv[2] = redis port (compose default 6380).
 */

use PHPdot\Contracts\Server\SseHandlerInterface;
use PHPdot\Contracts\Server\WebSocketHandlerInterface;
use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Http\Factory\ResponseFactory as HttpFactory;
use PHPdot\Server\Cluster\Heartbeat;
use PHPdot\Server\Cluster\HeartbeatHalt;
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
$redisPort = (int) ($argv[2] ?? 6380);

if ($port <= 0) {
    fwrite(STDERR, "usage: server_loaded_runner.php <port> [redisPort]\n");
    exit(1);
}

$factory = new ResponseFactory();

$master = new ServerConfig(workerNum: 2, hookFlags: SWOOLE_HOOK_ALL);
$http = new HttpServerConfig(host: '127.0.0.1', port: $port);

$handler = new class ($factory) implements RequestHandlerInterface, SseHandlerInterface, WebSocketHandlerInterface {
    public function __construct(private readonly ResponseFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (str_contains($request->getUri()->getPath(), 'slow')) {
            sleep(1);
        }

        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('SLOW-OK'));
    }

    public function handleSse(ServerRequestInterface $request, Closure $write, Closure $close): bool
    {
        if (!str_contains($request->getUri()->getPath(), 'stream')) {
            return false;
        }

        $write("event: hello\ndata: {}\n\n");

        /*
         * A stream that runs until the reader goes or the server drains —
         * exactly the coroutine that pinned recycling workers before the
         * cancel-on-exit fix. Bounded by Coroutine::isCanceled so the drain
         * can end it.
         */
        while (\Swoole\Coroutine::isCanceled() === false) {
            $alive = $write("event: tick\ndata: {}\n\n");

            if ($alive === false) {
                return true;
            }

            \Swoole\Coroutine::sleep(0.2);
        }

        return true;
    }

    public function handleWsOpen(int $fd, ServerRequestInterface $request, Closure $send, Closure $sendBinary, Closure $close): bool
    {
        return true;
    }

    public function handleWsMessage(int $fd, string $data, int $opcode): void
    {
        /*
         * Nothing to echo with — the WS connection's mere existence during
         * drain is what this fixture needs: the exit sweep must disconnect
         * it while I/O answers.
         */
    }

    public function handleWsClose(int $fd, int $code, string $reason): void
    {
        echo "WS-CLOSED {$fd}\n";
    }
};

$server = new Server($master);
$server->attach(new HttpServer(new HttpFactory(), $http));

/*
 * The real Heartbeat pair over the compose Redis, subscribed by hand — the
 * same objects the listener bridge would dispatch, including the halt that
 * cancels the in-flight beat.
 */
$container = new class ($redisPort) implements Psr\Container\ContainerInterface {
    public function __construct(private readonly int $redisPort) {}

    public function get(string $id): object
    {
        if ($id === \PHPdot\Redis\Config\RedisConfig::class) {
            return new \PHPdot\Redis\Config\RedisConfig(
                host: '127.0.0.1',
                port: $this->redisPort,
                timeout: 1.0,
                readTimeout: 1.0,
            );
        }

        throw new RuntimeException("Service '{$id}' not found.");
    }

    public function has(string $id): bool
    {
        return $id === \PHPdot\Redis\Config\RedisConfig::class;
    }
};

$heartbeat = new Heartbeat($container, $master, $http);

$server->events()->subscribe($heartbeat);
$server->events()->subscribe(new HeartbeatHalt($heartbeat));

$server->serve($handler);
