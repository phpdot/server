<?php

declare(strict_types=1);

/**
 * M3 SSE test runner. Boots a Server with an HttpServer whose aggregate handler
 * speaks PSR-15 + SseHandlerInterface. Streams on an event-stream request:
 *   /events        -> one `data: hello` frame
 *   /events-multi  -> retry + several id/event/data frames (multi-frame parity)
 *   /decline       -> streams one frame then returns false (contract misuse:
 *                     the server must abort, not blend in the PSR-15 response)
 * Proves the SSE branch streams on the wire. Launched as a child process by
 * SseTest; argv[1] = port.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Contracts\Server\SseHandlerInterface;
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
    fwrite(STDERR, "usage: server_sse_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface, SseHandlerInterface {
    public function __construct(private readonly ResponseFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream('PSR15-FALLBACK'));
    }

    public function handleSse(ServerRequestInterface $request, Closure $write, Closure $close): bool
    {
        if ($request->getUri()->getPath() === '/decline') {
            $write("data: partial\n\n");

            return false;
        }

        if ($request->getUri()->getPath() === '/events-multi') {
            $write("retry: 3000\n\n");
            $write("id: 1\nevent: tick\ndata: one\n\n");
            $write("id: 2\nevent: tick\ndata: two\n\n");
            $write("id: 3\nevent: tick\ndata: three\n\n");

            return true;
        }

        $write("data: hello\n\n");

        return true;
    }
};

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));
$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));
$server->serve($handler);
