<?php

declare(strict_types=1);

/**
 * Upload test runner. Echoes the SHAPE of getUploadedFiles() as JSON — each
 * leaf rendered "file:<clientFilename>:<size>" — so tests can assert how the
 * converter normalizes Swoole's multipart output (simple, array, and nested
 * bracketed field names). Launched as a child process by UploadTest;
 * argv[1] = port.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
if ($port <= 0) {
    fwrite(STDERR, "usage: server_upload_runner.php <port>\n");
    exit(1);
}

$factory = new ResponseFactory();

$handler = new class ($factory) implements RequestHandlerInterface {
    public function __construct(private readonly ResponseFactory $factory) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $shape = $this->describe($request->getUploadedFiles());

        return $this->factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream((string) json_encode($shape)));
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private function describe(array $files): array
    {
        $out = [];

        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $out[$key] = 'file:' . $value->getClientFilename() . ':' . $value->getSize();
            } elseif (is_array($value)) {
                $out[$key] = $this->describe($value);
            } else {
                $out[$key] = 'unexpected:' . get_debug_type($value);
            }
        }

        return $out;
    }
};

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: 0));
$server->attach(new HttpServer($factory, new HttpServerConfig(host: '127.0.0.1', port: $port)));
$server->serve($handler);
