<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit;

use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Event\WorkerExiting;
use PHPdot\Server\Event\WorkerStarted;
use PHPdot\Server\Listener\ListenerBridge;
use PHPdot\Server\Listener\ListenerSignature;
use PHPdot\Server\Server;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * ListenerBridge contract: events reach the listeners routed to them,
 * instances are constructed LAZILY through the container (plan lesson L2 —
 * post-fork; the bridge itself must never reflect or load a listener class,
 * it lives in the pre-fork master), a throwing listener is isolated (logged,
 * siblings still run), and a non-invokable is logged, never fatal. Routes
 * come from ListenerScan's subprocess; ListenerSignature's resolution rules
 * are covered here too.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ListenerBridgeTest extends TestCase
{
    /**
     * @var array<string, int> Container build counts per class.
     */
    public static array $built = [];

    /**
     * @var list<string> Invocation log shared by the fixture listeners.
     */
    public static array $calls = [];

    /**
     * @var list<string> Bridge log lines captured through the injected logger.
     */
    private array $logs = [];

    protected function setUp(): void
    {
        self::$built = [];
        self::$calls = [];
        $this->logs = [];
    }

    #[Test]
    public function routesEventsToTheirListeners(): void
    {
        $bridge = new ListenerBridge($this->container(), routes: [
            WorkerStarted::class => [StartProbe::class],
            WorkerExiting::class => [ExitProbe::class],
        ], logger: $this->collector());

        $server = new Server(new ServerConfig(workerNum: 1));
        $bridge->onWorkerStart($server, 3);

        self::assertSame(['start:3'], self::$calls, 'only the WorkerStarted listener may fire');

        $bridge->onWorkerExit($server, 3);

        self::assertSame(['start:3', 'exit:3'], self::$calls, 'the WorkerExiting listener fires on exit');
    }

    #[Test]
    public function constructsListenersLazilyAndOnce(): void
    {
        $bridge = new ListenerBridge($this->container(), routes: [
            WorkerStarted::class => [StartProbe::class],
            WorkerExiting::class => [ExitProbe::class],
        ], logger: $this->collector());
        $server = new Server(new ServerConfig(workerNum: 1));

        self::assertSame([], self::$built, 'nothing may be constructed before the first event');

        $bridge->onWorkerStart($server, 1);
        $bridge->onWorkerStart($server, 1);

        self::assertSame([StartProbe::class => 1], self::$built, 'one instance, reused; unrelated listeners untouched');
    }

    #[Test]
    public function aThrowingListenerIsIsolated(): void
    {
        $bridge = new ListenerBridge($this->container(), routes: [
            WorkerStarted::class => [ThrowingProbe::class, StartProbe::class],
        ], logger: $this->collector());
        $server = new Server(new ServerConfig(workerNum: 1));

        $bridge->onWorkerStart($server, 7);

        self::assertContains('start:7', self::$calls, 'siblings must still run after a listener throws');
        self::assertCount(1, $this->logs, 'the isolated failure must be logged exactly once');
        self::assertStringContainsString('listener exploded', $this->logs[0]);
    }

    #[Test]
    public function aNonInvokableListenerIsIsolated(): void
    {
        $bridge = new ListenerBridge($this->container(), routes: [
            WorkerStarted::class => [BrokenProbe::class, StartProbe::class],
        ], logger: $this->collector());
        $server = new Server(new ServerConfig(workerNum: 1));

        $bridge->onWorkerStart($server, 9);

        self::assertSame(['start:9'], self::$calls, 'a listener without __invoke never runs');
        self::assertCount(1, $this->logs, 'the misdeclaration must be logged exactly once');
        self::assertStringContainsString('invokable', $this->logs[0]);
    }

    #[Test]
    public function signatureResolvesTheInvokeEventType(): void
    {
        self::assertSame(WorkerStarted::class, ListenerSignature::eventTypeOf(StartProbe::class));
        self::assertSame(WorkerExiting::class, ListenerSignature::eventTypeOf(ExitProbe::class));
    }

    #[Test]
    public function signatureRejectsAMisdeclaredListener(): void
    {
        self::assertNull(ListenerSignature::eventTypeOf(BrokenProbe::class), 'no __invoke → no event');
        self::assertNull(ListenerSignature::eventTypeOf('PHPdot\\Server\\Tests\\Unit\\DoesNotExist'), 'unloadable → no event');
    }

    /**
     * A logger closure that collects bridge log lines for assertions.
     *
     * @return \Closure(string): void
     */
    private function collector(): \Closure
    {
        return function (string $message): void {
            $this->logs[] = $message;
        };
    }

    /**
     * A counting container that builds fixture listeners on demand.
     *
     * @return ContainerInterface
     */
    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                ListenerBridgeTest::$built[$id] = (ListenerBridgeTest::$built[$id] ?? 0) + 1;

                return new $id();
            }

            public function has(string $id): bool
            {
                return class_exists($id);
            }
        };
    }
}

#[ServerListener]
final class StartProbe
{
    public function __invoke(WorkerStarted $event): void
    {
        ListenerBridgeTest::$calls[] = 'start:' . $event->workerId;
    }
}

#[ServerListener]
final class ExitProbe
{
    public function __invoke(WorkerExiting $event): void
    {
        ListenerBridgeTest::$calls[] = 'exit:' . $event->workerId;
    }
}

#[ServerListener]
final class ThrowingProbe
{
    public function __invoke(WorkerStarted $event): void
    {
        throw new RuntimeException('listener exploded');
    }
}

#[ServerListener]
final class BrokenProbe
{
    public function handle(WorkerStarted $event): void
    {
        ListenerBridgeTest::$calls[] = 'broken';
    }
}
