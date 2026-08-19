<?php

declare(strict_types=1);

/**
 * ServerListener — marks a class as a server lifecycle listener, discovered by
 * attribute scan and invoked by the ListenerBridge. The class declares ONE
 * __invoke whose parameter type is the lifecycle event it wants (WorkerStarted,
 * WorkerExiting, ServerStarted, ServerShutdown); instances are constructed
 * LAZILY per worker through the container (post-fork), and a throwing listener
 * is logged, never allowed to kill the worker.
 *
 * Deliberately distinct from phpdot/event's #[Listener]: lifecycle listeners
 * are sync-only, in-process, and worker-scoped — none of the event package's
 * async/ordering/persistence semantics can apply here, and a dedicated
 * attribute keeps discovery scoped to exactly these classes.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class ServerListener {}
