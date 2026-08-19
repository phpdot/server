<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Fixtures\Listener;

use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Event\WorkerStarted;

/**
 * A misdeclared #[ServerListener] fixture — no __invoke — so the ListenerScan
 * subprocess must report it as skipped instead of routing it.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
#[ServerListener]
final class ScanBroken
{
    /**
     * Wrong method name on purpose; the scan must reject this class.
     *
     * @param WorkerStarted $event The lifecycle event
     *
     * @return void
     */
    public function handle(WorkerStarted $event): void {}
}
