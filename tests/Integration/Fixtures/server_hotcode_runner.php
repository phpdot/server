<?php

declare(strict_types=1);

/**
 * Hot-code test runner. Boots a two-worker BASE-mode Server whose handler
 * answers with the marker of a HotPayload class autoloaded LAZILY per worker
 * from the file named by PHPDOT_TEST_PAYLOAD_FILE — never loaded pre-fork.
 * ReloadAppliesCodeChangesTest rewrites that file and reloads to prove fresh
 * workers serve the code that is on disk NOW, not a boot-time memory copy.
 * Launched as a child process; argv[1] = port.
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
    fwrite(STDERR, "usage: server_hotcode_runner.php <port>\n");
    exit(1);
}

$payloadFile = (string) (getenv('PHPDOT_TEST_PAYLOAD_FILE') ?: '');

spl_autoload_register(static function (string $class) use ($payloadFile): void {
    if ($class === 'HotPayload' && $payloadFile !== '' && is_file($payloadFile)) {
        require $payloadFile;
    }
});

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface {
    public function __construct(private readonly ResponseFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream(new \HotPayload()->marker()));
    }
};

$server = new Server(new ServerConfig(workerNum: 2, mode: SWOOLE_BASE, hookFlags: 0));
$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));
$server->serve($handler);
