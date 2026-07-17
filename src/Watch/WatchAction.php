<?php

declare(strict_types=1);

/**
 * WatchAction.
 *
 * What a changed file triggers under the development watcher: a graceful worker
 * reload (app code, loaded after the fork), a notice that a full restart is
 * required (code loaded before the fork — config, bootstrap), or no action.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Watch;

enum WatchAction: string
{
    case Reload = 'reload';
    case Restart = 'restart';
    case Ignore = 'ignore';
}
