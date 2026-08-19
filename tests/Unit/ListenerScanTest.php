<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit;

use PHPdot\Server\Event\WorkerStarted;
use PHPdot\Server\Listener\ListenerScan;
use PHPdot\Server\Tests\Fixtures\Listener\ScanBroken;
use PHPdot\Server\Tests\Fixtures\Listener\ScanProbe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ListenerScan contract: discovery happens in a SUBPROCESS that returns pure
 * strings — routes keyed by event type, misdeclared classes as skipped — and
 * absolutely nothing gets loaded into the calling process. That last property
 * is the whole point: a class loaded pre-fork is frozen into every worker
 * generation, and reload silently stops applying its edits.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ListenerScanTest extends TestCase
{
    #[Test]
    public function scanRoutesListenersAndReportsSkippedWithoutLoadingThem(): void
    {
        $result = new ListenerScan()->run([dirname(__DIR__) . '/Fixtures/Listener']);

        self::assertSame(
            [ScanProbe::class],
            $result['routes'][WorkerStarted::class] ?? [],
            'the well-formed listener must route to its __invoke event type',
        );
        self::assertContains(ScanBroken::class, $result['skipped'], 'a listener without __invoke must be reported');

        self::assertFalse(
            class_exists(ScanProbe::class, false),
            'the scan must not load listener classes into the calling process',
        );
        self::assertFalse(
            class_exists(ScanBroken::class, false),
            'the scan must not load skipped classes into the calling process',
        );
    }

    #[Test]
    public function scanOfNothingSpawnsNothingAndReturnsEmpty(): void
    {
        $result = new ListenerScan()->run(['/nonexistent/path/' . uniqid()]);

        self::assertSame(['routes' => [], 'skipped' => []], $result);
    }
}
