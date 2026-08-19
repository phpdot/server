<?php

declare(strict_types=1);

/**
 * ListenerBridge — delivers server lifecycle moments to #[ServerListener]
 * classes. Subscribed to the LifecycleEventRegistry through the typed On*
 * SPI like any transport; routes each typed event (ServerStarted,
 * WorkerStarted, WorkerExiting, ServerShutdown) to the listeners routed to
 * it. Routes arrive as pure strings from the boot-time subprocess scan
 * (ListenerScan) — this class must never reflect a listener, because it
 * lives in the pre-fork master and a class loaded there is frozen into
 * every worker generation, exempting it from reload. INSTANCES are
 * constructed lazily through the container in whichever process first needs
 * them — post-fork in workers, per plan lesson L2, which is also the moment
 * the listener's code loads from current disk. A throwing listener is
 * logged and isolated; it never kills the worker.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Listener;

use PHPdot\Server\Contract\OnShutdownInterface;
use PHPdot\Server\Contract\OnStartInterface;
use PHPdot\Server\Contract\OnWorkerExitInterface;
use PHPdot\Server\Contract\OnWorkerStartInterface;
use PHPdot\Server\Event\ServerShutdown;
use PHPdot\Server\Event\ServerStarted;
use PHPdot\Server\Event\WorkerExiting;
use PHPdot\Server\Event\WorkerStarted;
use PHPdot\Server\Server;
use Psr\Container\ContainerInterface;
use Throwable;

final class ListenerBridge implements
    OnStartInterface,
    OnWorkerStartInterface,
    OnWorkerExitInterface,
    OnShutdownInterface
{
    /**
     * @var array<string, callable> Listener instances, built lazily per process.
     */
    private array $instances = [];

    /**
     * Create the bridge over the container and the discovered routes. Route
     * keys and values are class NAMES as plain strings (they crossed the
     * ListenerScan process boundary as JSON); nothing here may sharpen them
     * to class-strings, because proving that means loading the class.
     *
     * @param ContainerInterface $container Builds listener instances on first use
     * @param array<string, list<string>> $routes Event class => listener classes,
     *                                            as resolved by the ListenerScan
     *                                            subprocess
     * @param \Closure(string): void|null $logger Receives isolation messages;
     *                                            null logs via error_log
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly array $routes = [],
        private readonly null|\Closure $logger = null,
    ) {}

    /**
     * Emit a bridge log line through the injected logger or error_log.
     *
     * @param string $message The log line
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);

            return;
        }

        error_log($message);
    }

    /**
     * @inheritDoc
     */
    public function onStart(Server $server): void
    {
        $this->dispatch(new ServerStarted($server));
    }

    /**
     * @inheritDoc
     */
    public function onWorkerStart(Server $server, int $workerId): void
    {
        $this->dispatch(new WorkerStarted($server, $workerId));
    }

    /**
     * @inheritDoc
     */
    public function onWorkerExit(Server $server, int $workerId): void
    {
        $this->dispatch(new WorkerExiting($server, $workerId));
    }

    /**
     * @inheritDoc
     */
    public function onShutdown(Server $server): void
    {
        $this->dispatch(new ServerShutdown($server));
    }

    /**
     * Deliver an event to every listener routed to its type, isolating throws.
     *
     * @param object $event The lifecycle event
     *
     * @return void
     */
    private function dispatch(object $event): void
    {
        foreach ($this->routes[$event::class] ?? [] as $class) {
            try {
                $listener = $this->instances[$class] ?? null;

                if ($listener === null) {
                    $candidate = $this->container->get($class);

                    if (!is_callable($candidate)) {
                        throw new \RuntimeException('container did not return an invokable listener');
                    }

                    $listener = $this->instances[$class] = $candidate;
                }

                $listener($event);
            } catch (Throwable $e) {
                $this->log(sprintf(
                    '[server-listener] %s failed on %s: %s',
                    $class,
                    $event::class,
                    $e->getMessage(),
                ));
            }
        }
    }

}
