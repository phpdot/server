<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end SSE test against a real Server process (Fixtures/server_sse_runner.php).
 * Sends an `Accept: text/event-stream` request over a raw socket and asserts a
 * streamed `data:` frame on the wire (read bounded by ServerTestCase's timeouts).
 */
final class SseTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_sse_runner.php';
    }

    #[Test]
    public function streamsEventSourceFrame(): void
    {
        $response = $this->rawRequest(
            "GET /events HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n",
        );

        self::assertStringContainsString('200', $this->statusLine($response), 'expected 200 OK');
        self::assertStringContainsStringIgnoringCase('text/event-stream', $response, 'expected SSE content type');
        self::assertStringContainsString('data: hello', $response, 'expected a streamed SSE data frame');
    }

    #[Test]
    public function streamsMultipleFramesWithIdsAndEventNames(): void
    {
        $response = $this->rawRequest(
            "GET /events-multi HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n",
        );

        self::assertStringContainsStringIgnoringCase('text/event-stream', $response, 'expected SSE content type');
        self::assertStringContainsString('retry: 3000', $response, 'expected a retry directive');
        self::assertStringContainsString('event: tick', $response, 'expected named events');
        self::assertStringContainsString('id: 1', $response, 'expected event ids');
        self::assertStringContainsString('data: one', $response, 'expected the first frame');
        self::assertStringContainsString('data: two', $response, 'expected the second frame');
        self::assertStringContainsString('data: three', $response, 'expected the third frame');
    }

    #[Test]
    public function handlerThatStreamedThenDeclinedAbortsInsteadOfBlendingBodies(): void
    {
        $response = $this->rawRequest(
            "GET /decline HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n",
        );

        self::assertStringContainsString('data: partial', $response, 'the streamed frame is already on the wire');
        self::assertStringNotContainsString(
            'PSR15-FALLBACK',
            $response,
            'the PSR-15 body must not be appended to a stream the SSE handler already started',
        );
    }
}
