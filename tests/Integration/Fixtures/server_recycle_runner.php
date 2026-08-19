<?php

declare(strict_types=1);

/**
 * Recycle/reload test runner. Boots a two-worker BASE-mode Server whose
 * max_request comes from the PHPDOT_TEST_MAX_REQUEST env var THROUGH
 * override() — the handler speaks SSE, so streaming auto-protection forces
 * max_request to 0 and only override() can restore recycling (which also
 * proves the escape hatch outranks the auto-guard). WorkerRecycleTest proves
 * recycling under sustained requests drops nothing and that an open SSE
 * stream (/sse ticks forever) survives a SIGUSR1 worker reload; /settings
 * exposes the master's effective Swoole settings for direct assertions.
 * PHPDOT_TEST_PID_FILE enables the pid file and therefore the control
 * socket beside it. Launched as a child process; argv[1] = port.
 */

use PHPdot\Contracts\Server\SseHandlerInterface;
use PHPdot\Http\Factory\ResponseFactory;
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
    fwrite(STDERR, "usage: server_recycle_runner.php <port>\n");
    exit(1);
}

$maxRequest = (int) (getenv('PHPDOT_TEST_MAX_REQUEST') ?: 0);

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface, SseHandlerInterface {
    public null|PHPdot\Server\Server $server = null;

    public function __construct(private readonly ResponseFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/settings') {
            return $this->factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream(
                    (string) json_encode($this->server?->getMaster()->setting ?? []),
                ));
        }

        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('OK-' . getmypid()));
    }

    public function handleSse(ServerRequestInterface $request, Closure $write, Closure $close): bool
    {
        for ($i = 1; $i <= 100; $i++) {
            $write("id: {$i}\ndata: tick-{$i}\n\n");
            usleep(100000);
        }

        return true;
    }
};

$server = new Server(new ServerConfig(
    workerNum: 2,
    mode: SWOOLE_BASE,
    pidFile: (string) (getenv('PHPDOT_TEST_PID_FILE') ?: ''),
    maxWaitTime: 10,
    hookFlags: 0,
));

if ($maxRequest > 0) {
    $server->override(['max_request' => $maxRequest]);
}

$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));
$handler->server = $server;
$server->serve($handler);
