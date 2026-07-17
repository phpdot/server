<?php

declare(strict_types=1);

/**
 * M1 integration-test server runner.
 *
 * Boots a REAL Server with one HttpServer (HTTP-only) and a routing PSR-15
 * handler, so the integration tests exercise the request callback +
 * RequestConverter/ResponseConverter on the wire. Launched as a separate
 * process by HttpParityTest; the port is argv[1].
 *
 * Routes:
 *   POST /echo-body    -> echoes the raw request body
 *   GET  /echo-request -> echoes method, query, header, cookie
 *   GET  /created      -> 201 + X-Request-Id header
 *   GET  /set-cookie   -> 200 + Set-Cookie header
 *   GET  /multi-header -> 200 + two custom headers
 *   GET  /status-404   -> 404 "nope"
 *   GET  /len          -> 200 "abcde" (Content-Length parity)
 *   GET  /json         -> 200 application/json
 *   GET  /no-content   -> 204 (empty body)
 *   GET  /big          -> 200 200 KB body (streamed/chunked path)
 *   GET  /boom         -> throws (leak-by-design: last-resort 500 passes the message through)
 *   GET  /scheme       -> echoes request scheme (SR-M2 target: honor X-Forwarded-Proto)
 *   GET  /www-auth     -> 401 + two WWW-Authenticate values (SR-M2 target: no comma-folding)
 *   *                  -> 200 "OK"
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

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: server_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface {
    public function __construct(
        private readonly ResponseFactory $factory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if ($path === '/echo-body') {
            return $this->text($request->getBody()->getContents());
        }

        if ($path === '/echo-request') {
            $name = $request->getQueryParams()['name'] ?? '';
            $cookie = $request->getCookieParams()['sid'] ?? '';

            return $this->text(sprintf(
                'm=%s;q=%s;h=%s;c=%s',
                $request->getMethod(),
                is_string($name) ? $name : '',
                $request->getHeaderLine('X-Test'),
                is_string($cookie) ? $cookie : '',
            ));
        }

        if ($path === '/created') {
            return $this->factory->createResponse(201)
                ->withHeader('Content-Type', 'text/plain')
                ->withHeader('X-Request-Id', 'r-123')
                ->withBody($this->factory->createStream('made'));
        }

        if ($path === '/set-cookie') {
            return $this->text('cookie-set')
                ->withHeader('Set-Cookie', 'session=abc123; Path=/; HttpOnly');
        }

        if ($path === '/multi-header') {
            return $this->text('multi')
                ->withHeader('X-Alpha', '1')
                ->withHeader('X-Beta', '2');
        }

        if ($path === '/status-404') {
            return $this->factory->createResponse(404)
                ->withHeader('Content-Type', 'text/plain')
                ->withBody($this->factory->createStream('nope'));
        }

        if ($path === '/len') {
            return $this->text('abcde');
        }

        if ($path === '/json') {
            return $this->factory->createResponse(200)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->factory->createStream('{"ok":true}'));
        }

        if ($path === '/no-content') {
            return $this->factory->createResponse(204);
        }

        if ($path === '/big') {
            return $this->text(str_repeat('0123456789', 20000)); // 200 KB — exercises the streamed/chunked path
        }

        if ($path === '/boom') {
            throw new \RuntimeException('SECRET_LEAK_TOKEN_9f3a');
        }

        if ($path === '/scheme') {
            return $this->text($request->getUri()->getScheme());
        }

        if ($path === '/www-auth') {
            return $this->factory->createResponse(401)
                ->withAddedHeader('WWW-Authenticate', 'Basic realm="api"')
                ->withAddedHeader('WWW-Authenticate', 'Bearer');
        }

        return $this->text('OK');
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

$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));
$server->serve($handler);
