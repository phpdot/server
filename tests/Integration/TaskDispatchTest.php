<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Proves TaskDispatcher dispatches to a task worker and the result returns: a
 * request triggers taskCo(['hello']), the onTask handler uppercases it, and the
 * response body is 'HELLO'. Also exercises Server::getMaster() + onTask wiring.
 */
final class TaskDispatchTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_task_runner.php';
    }

    #[Test]
    public function taskRoundTripsThroughTaskWorker(): void
    {
        $response = $this->rawRequest("GET /upper HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('200', $this->statusLine($response), 'expected 200 OK');
        self::assertStringContainsString('HELLO', $this->bodyOf($response), 'expected the uppercased task result');
    }
}
