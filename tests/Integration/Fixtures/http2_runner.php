<?php

declare(strict_types=1);

/**
 * Integration-test server runner for HTTP/2: one worker, h2c enabled, a route that answers late with a cookie and a
 * route that names the worker's process, so a test can tell whether an abandoned stream took the worker down.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Contract\OnStartInterface;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Coroutine;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: http2_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface {
    public function __construct(
        private readonly ResponseFactory $factory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getUri()->getPath() === '/late-cookie') {
            Coroutine::sleep(0.5);

            return $this->text('late')->withHeader('Set-Cookie', 'session=abc123; Path=/; HttpOnly');
        }

        return $this->text((string) getmypid());
    }

    private function text(string $body): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream($body));
    }
};

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));

$server->events()->subscribe(new class implements OnStartInterface {
    public function onStart(Server $server): void
    {
        echo "READY\n";
    }
});

$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port, http2: true)));
$server->serve($handler);
