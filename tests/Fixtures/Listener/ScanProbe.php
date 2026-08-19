<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Fixtures\Listener;

use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Event\WorkerStarted;

/**
 * A well-formed #[ServerListener] fixture for the ListenerScan subprocess
 * tests — discoverable by the token scan and autoloadable by name, so the
 * subprocess can reflect it while the test process itself never loads it.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
#[ServerListener]
final class ScanProbe
{
    /**
     * Record nothing; existence and signature are the fixture.
     *
     * @param WorkerStarted $event The lifecycle event
     *
     * @return void
     */
    public function __invoke(WorkerStarted $event): void {}
}
