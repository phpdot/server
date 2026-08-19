<?php

declare(strict_types=1);

/**
 * StopResult — the outcome of a ProcessController::stop() attempt: graceful
 * drain, forced kill after the grace window, or nothing was running.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Process;

enum StopResult: string
{
    case Graceful = 'graceful';
    case Forced = 'forced';
    case NotRunning = 'not-running';
}
