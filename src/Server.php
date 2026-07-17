<?php

declare(strict_types=1);

/**
 * Server — the process owner. Creates the single Swoole master, owns lifecycle
 * and the event loop, and runs every attached Transport's register() before
 * start(). The sole host: transports are always attachments, never hosts.
 *
 * The primary transport (HttpServer if any is attached, else the first) provides
 * the master's main-port bind; its class follows — `WebSocket\Server` for HTTP+WS,
 * `Http\Server` for HTTP, a plain `Swoole\Server` otherwise (TCP-only). Every
 * other transport adds a port via listen() and wires itself on.
 *
 * Capability services (TaskDispatcher, Timer, ConnectionRegistry, ProcessManager,
 * ServerStats, Watcher) are resolved separately and reach the master via
 * getMaster(); ProcessManager is wired here pre-start via attachTo().
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server;

use Closure;
use PHPdot\Container\Attribute\Binds;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Server\ServerInterface;
use PHPdot\Contracts\Server\WebSocketHandlerInterface;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Contract\Transport;
use PHPdot\Server\Event\LifecycleEventRegistry;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Process\OrphanWatchdog;
use PHPdot\Server\Process\ProcessManager;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Server as SwooleWebSocketServer;

#[Singleton]
#[Binds(ServerInterface::class)]
final class Server implements ServerInterface
{
    /**
     * @var list<Transport> transports attached before serve().
     */
    private array $transports = [];

    /**
     * The Swoole master; null before serve().
     */
    private SwooleServer|null $swoole = null;

    /**
     * Task/finish event handlers (server-level); wired pre-start if set.
     */
    private Closure|null $onTaskHandler = null;

    private Closure|null $onFinishHandler = null;

    /**
     * __construct.
     *
     * @param ServerConfig $config
     * @param LifecycleEventRegistry $events
     * @param ProcessManager $processes
     */
    public function __construct(
        private readonly ServerConfig $config = new ServerConfig(),
        private readonly LifecycleEventRegistry $events = new LifecycleEventRegistry(),
        private readonly ProcessManager $processes = new ProcessManager(),
    ) {}

    /**
     * Attach a transport (HttpServer, TcpServer, …). Transport-agnostic: the
     * runner's only transport-specific knowledge is the single instanceof
     * HttpServer check that picks the primary/master class. Call before serve().
     *
     * @param Transport $transport
     *
     * @return $this
     */
    public function attach(Transport $transport): static
    {
        $this->transports[] = $transport;

        return $this;
    }

    /**
     * Boot the server. Blocks on the Swoole event loop until shutdown.
     *
     * One start(): the primary transport owns the main port; every other
     * transport adds a port via listen() and wires itself on (pre-start).
     *
     * The handler may also implement the WS/SSE/TCP protocol-handler interfaces
     * as an aggregate.
     *
     * @param RequestHandlerInterface $handler
     */
    public function serve(RequestHandlerInterface $handler): void
    {
        if ($this->swoole !== null) {
            throw new ServerException('Server already started — serve() may only be called once per process.');
        }

        if ($this->transports === []) {
            throw new ServerException('Server has no transports. attach() an HttpServer and/or TcpServer before serve().');
        }

        $this->ensurePortsAvailable();

        $primary = $this->primaryTransport();
        $isHttp = $primary instanceof HttpServer;
        $isWebSocket = $isHttp && $handler instanceof WebSocketHandlerInterface;

        $swoole = $this->createMaster($primary, $isHttp, $isWebSocket);
        $this->swoole = $swoole;

        $swoole->set(array_merge($this->config->toArray(), $primary->settings()));

        $primary->register($swoole, true, $handler);

        foreach ($this->transports as $transport) {
            if ($transport === $primary) {
                continue;
            }
            $transport->register($swoole, false, $handler);
        }

        foreach ($this->transports as $transport) {
            $this->events->subscribe($transport);
        }

        $this->events->register($swoole, $this);

        if ($this->onTaskHandler !== null) {
            $swoole->on('task', $this->onTaskHandler);
        }

        if ($this->onFinishHandler !== null) {
            $swoole->on('finish', $this->onFinishHandler);
        }

        $this->processes->attachTo($swoole);

        if ($this->config->orphanWatchdog && $this->config->mode === SWOOLE_PROCESS) {
            $swoole->addProcess(new \Swoole\Process(static function () use ($swoole): void {
                new OrphanWatchdog()->run($swoole);
            }, false, 0, true));
        }

        if ($this->config->hookFlags !== 0) {
            \Swoole\Runtime::enableCoroutine($this->config->hookFlags);
        }

        $swoole->start();
    }

    /**
     * Fail fast if any attached transport's TCP port is already in use, naming the
     * exact host:port. Without this a clash surfaces as Swoole's late bind error (or,
     * after a botched kill that frees the port but strands workers, as startup churn).
     * A brief bind-probe: if we can bind + listen, the port is free; then we release it
     * (an unaccepted listen socket frees immediately, so Swoole binds it moments later).
     *
     * Public so a runner can check + announce before serving; serve() calls it too.
     *
     * @throws ServerException If a port is already bound.
     *
     * @return void
     */
    public function ensurePortsAvailable(): void
    {
        foreach ($this->transports as $transport) {
            $host = $transport->host();
            $port = $transport->port();

            $baseSockType = $transport->sockType() & ~SWOOLE_SSL;

            if ($port <= 0 || ($baseSockType !== SWOOLE_SOCK_TCP && $baseSockType !== SWOOLE_SOCK_TCP6)) {
                continue;
            }

            $probeHost = str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]" : $host;

            $errno = 0;
            $errstr = '';
            $probe = @stream_socket_server(
                "tcp://{$probeHost}:{$port}",
                $errno,
                $errstr,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            );

            if ($probe === false) {
                $reason = trim($errstr ?? '');

                if ($reason === '') {
                    $reason = 'errno ' . ($errno ?? 0);
                }

                throw new ServerException(sprintf(
                    'Port %d (%s) is already in use — is another server still running? '
                    . 'Stop it (Ctrl+C), or free it: lsof -ti tcp:%d | xargs kill — then retry. [%s]',
                    $port,
                    $host,
                    $port,
                    $reason,
                ));
            }

            fclose($probe);
        }
    }

    /**
     * Gracefully shut the server down.
     *
     * @throws ServerException If the server has not been started.
     *
     * @return bool
     */
    public function shutdown(): bool
    {
        return $this->requireMaster()->shutdown();
    }

    /**
     * Reload worker processes.
     *
     * @param bool $onlyReloadTaskWorker Only reload task workers.
     *
     * @throws ServerException If the server has not been started.
     *
     * @return bool
     */
    public function reload(bool $onlyReloadTaskWorker = false): bool
    {
        return $this->requireMaster()->reload($onlyReloadTaskWorker);
    }

    /**
     * Stop a worker process.
     *
     * @param int $workerId Worker ID to stop (-1 for current).
     * @param bool $waitEvent Wait for events to complete.
     *
     * @throws ServerException If the server has not been started.
     *
     * @return bool
     */
    public function stop(int $workerId = -1, bool $waitEvent = false): bool
    {
        return $this->requireMaster()->stop($workerId, $waitEvent);
    }

    /**
     * The running Swoole master. Capability services resolve this (post-start).
     *
     * @throws ServerException If the server has not been started.
     *
     * @return \Swoole\Server
     */
    public function getMaster(): SwooleServer
    {
        return $this->requireMaster();
    }

    /**
     * Register the task worker handler. The closure receives
     * (Swoole\Server, int $taskId, int $srcWorkerId, mixed $data); call
     * TaskDispatcher::finish() to return a result. Must be called before serve().
     *
     * @param Closure(\Swoole\Server, int, int, mixed): void $handler
     *
     * @return void
     */
    public function onTask(Closure $handler): void
    {
        $this->onTaskHandler = $handler;
    }

    /**
     * Register the task-completion handler. The closure receives
     * (Swoole\Server, int $taskId, mixed $data). Must be called before serve().
     *
     * @param Closure(\Swoole\Server, int, mixed): void $handler
     *
     * @return void
     */
    public function onFinish(Closure $handler): void
    {
        $this->onFinishHandler = $handler;
    }

    /**
     * Config.
     *
     * @return ServerConfig
     */
    public function config(): ServerConfig
    {
        return $this->config;
    }

    /**
     * Events.
     *
     * @return LifecycleEventRegistry
     */
    public function events(): LifecycleEventRegistry
    {
        return $this->events;
    }

    /**
     * Processes.
     *
     * @return ProcessManager
     */
    public function processes(): ProcessManager
    {
        return $this->processes;
    }

    /**
     * Ports of the attached HttpServer transports. ConnectionRegistry uses this
     * to keep raw broadcasts off HTTP/WS sockets (raw bytes corrupt those protocols).
     *
     * @return list<int>
     */
    public function httpPorts(): array
    {
        $ports = [];

        foreach ($this->transports as $transport) {
            if ($transport instanceof HttpServer) {
                $ports[] = $transport->port();
            }
        }

        return $ports;
    }

    /**
     * The primary transport: HttpServer if any is attached, else the first.
     *
     * @return Transport
     */
    private function primaryTransport(): Transport
    {
        foreach ($this->transports as $transport) {
            if ($transport instanceof HttpServer) {
                return $transport;
            }
        }

        return $this->transports[0];
    }

    /**
     * Build the master from the primary's bind.
     *
     * HTTP primary → Swoole\WebSocket\Server when the handler speaks WS (the
     * HTTP+WS superset, which also carries added TCP ports), else Http\Server.
     * A plain Swoole\Server is used for a TCP-only primary (no HTTP transport).
     *
     * @param Transport $primary
     * @param bool $isHttp
     * @param bool $isWebSocket
     *
     * @return \Swoole\Server
     */
    private function createMaster(Transport $primary, bool $isHttp, bool $isWebSocket): SwooleServer
    {
        if ($isHttp && $isWebSocket) {
            return new SwooleWebSocketServer($primary->host(), $primary->port(), $this->config->mode, $primary->sockType());
        }

        if ($isHttp) {
            return new SwooleHttpServer($primary->host(), $primary->port(), $this->config->mode, $primary->sockType());
        }

        return new SwooleServer($primary->host(), $primary->port(), $this->config->mode, $primary->sockType());
    }

    /**
     * Require master.
     *
     * @return SwooleServer
     */
    private function requireMaster(): SwooleServer
    {
        if ($this->swoole === null) {
            throw new ServerException('Server has not been started. Call serve() first.');
        }

        return $this->swoole;
    }
}
