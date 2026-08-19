<?php

declare(strict_types=1);

/**
 * ServerFactory — config-driven assembly of a ready-to-serve Server. Reads the
 * transport configs, attaches every enabled transport, and fails at creation
 * time when nothing is enabled, so misconfiguration surfaces at boot instead of
 * inside serve(). Consumers call create()->serve($handler) and never learn the
 * attach ritual.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server;

use PHPdot\Container\Attribute\Singleton;
use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Listener\ListenerBridge;
use PHPdot\Server\Listener\ListenerScan;
use PHPdot\Server\Tcp\TcpServer;
use Psr\Container\ContainerInterface;

#[Singleton]
final class ServerFactory
{
    /**
     * @var list<string> Directories to scan for #[ServerListener] classes.
     */
    private array $listenerDirs = [];

    /**
     * Create the factory over the server, its transport configs, and the container.
     *
     * @param Server $server The server to assemble
     * @param HttpServerConfig $http HTTP transport configuration
     * @param TcpServerConfig $tcp TCP transport configuration
     * @param ResponseFactory $responseFactory PSR-17 factory set for the HTTP transport
     * @param ContainerInterface $container Builds #[ServerListener] instances post-fork
     */
    public function __construct(
        private readonly Server $server,
        private readonly HttpServerConfig $http,
        private readonly TcpServerConfig $tcp,
        private readonly ResponseFactory $responseFactory,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * Add directories to scan for #[ServerListener] classes — the application
     * wires its own layout here, exactly like console command discovery
     * (Application::discover in the entry): vendor/phpdot for package-shipped
     * listeners plus the app's code roots. Call before create().
     *
     * @param list<string> $directories Absolute directories to scan
     *
     * @return $this
     */
    public function discover(array $directories): static
    {
        $this->listenerDirs = array_merge($this->listenerDirs, $directories);

        return $this;
    }

    /**
     * Attach every enabled transport, subscribe discovered #[ServerListener]
     * classes, and return the ready server.
     *
     * @throws ServerException When no transport is enabled.
     *
     * @return Server
     */
    public function create(): Server
    {
        $attached = 0;

        if ($this->http->enabled) {
            $this->server->attach(new HttpServer($this->responseFactory, $this->http));
            ++$attached;
        }

        if ($this->tcp->enabled) {
            $this->server->attach(new TcpServer($this->tcp));
            ++$attached;
        }

        if ($attached === 0) {
            throw new ServerException(
                'No transport enabled. Set enabled => true in config/server/http.php '
                . 'or config/server/tcp.php before starting the server.',
            );
        }

        $routes = $this->discoverListeners();

        if ($routes !== []) {
            $this->server->events()->subscribe(new ListenerBridge($this->container, $routes));
        }

        return $this->server;
    }

    /**
     * Discover #[ServerListener] routes for the discover()-registered
     * directories. Runs in a SUBPROCESS (ListenerScan): scanning reflects and
     * therefore loads classes, and any class loaded pre-fork is frozen into
     * every worker generation — reload would stop applying its edits. Only
     * class-name strings enter this process; instances are never built here
     * (L2 — the bridge constructs them lazily inside whichever process needs
     * them, which also loads their code post-fork from current disk).
     *
     * @return array<string, list<string>>
     */
    private function discoverListeners(): array
    {
        $result = new ListenerScan()->run($this->listenerDirs);

        foreach ($result['skipped'] as $class) {
            error_log(sprintf(
                '[server-listener] %s ignored — a #[ServerListener] needs __invoke(SomeLifecycleEvent $event).',
                $class,
            ));
        }

        return $result['routes'];
    }
}
