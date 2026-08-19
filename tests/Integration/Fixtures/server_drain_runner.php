<?php

declare(strict_types=1);

/**
 * Drain test runner. Boots a single-worker BASE-mode Server whose /slow route
 * blocks for one second, so DrainOnStopTest can assert that a SIGTERM arriving
 * mid-request lets the response complete (BASE workers own their connections)
 * and the process then exits. Launched as a child process; argv[1] = port.
 */

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
    fwrite(STDERR, "usage: server_drain_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface {
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
};

$server = new Server(new ServerConfig(workerNum: 1, mode: SWOOLE_BASE, hookFlags: 0));
$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));
$server->serve($handler);
