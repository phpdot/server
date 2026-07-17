<?php

declare(strict_types=1);

/**
 * M4 task-dispatch test runner. Boots a Server with one task worker, registers
 * an onTask handler (uppercases the payload, returns it via finish()), and serves
 * a PSR-15 handler that dispatches 'hello' through TaskDispatcher::taskCo() and
 * returns the uppercased result. Proves TaskDispatcher + master access + the
 * onTask/onFinish event wiring. Launched as a child process; argv[1] = port.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;
use PHPdot\Server\Task\TaskDispatcher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Server as SwooleServer;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: server_task_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();

$server = new Server(new ServerConfig(workerNum: 1, taskWorkerNum: 1, hookFlags: SWOOLE_HOOK_ALL));
$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));

$taskDispatcher = new TaskDispatcher($server);

// onTask: uppercase the payload and return it via finish() on the master.
$server->onTask(static function (SwooleServer $master, int $taskId, int $srcWorkerId, mixed $data): void {
    $master->finish(strtoupper((string) $data));
});

$handler = new class ($taskDispatcher, $factory) implements RequestHandlerInterface {
    public function __construct(
        private readonly TaskDispatcher $taskDispatcher,
        private readonly ResponseFactory $factory,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $results = $this->taskDispatcher->taskCo(['hello']);
        $body = is_array($results) ? (string) ($results[0] ?? '') : '';

        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->factory->createStream($body));
    }
};

$server->serve($handler);
