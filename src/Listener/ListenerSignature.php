<?php

declare(strict_types=1);

/**
 * ListenerSignature — resolves the lifecycle event a #[ServerListener] class
 * subscribes to: the sole parameter type of its __invoke method.
 *
 * Reflection here LOADS the class, so this must only run where loading is
 * safe: inside the scan-listeners subprocess (which dies after the scan) or a
 * post-fork worker — never in the pre-fork master, where a loaded class is
 * frozen into every future worker generation and reload stops applying edits.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Listener;

use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class ListenerSignature
{
    /**
     * The event class a listener subscribes to, null when the class has no
     * usable __invoke(SomeLifecycleEvent $event) signature.
     *
     * @param class-string $class The listener class
     *
     * @return class-string|null
     */
    public static function eventTypeOf(string $class): string|null
    {
        try {
            $invoke = new ReflectionMethod($class, '__invoke');
            $type = ($invoke->getParameters()[0] ?? null)?->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $name = $type->getName();

                if (class_exists($name)) {
                    return $name;
                }
            }
        } catch (Throwable) {
        }

        return null;
    }
}
