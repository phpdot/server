<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end HTTP parity for M1: a real request flows through RequestConverter
 * → PSR-15 handler → ResponseConverter, asserted at the byte level over raw TCP.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class HttpParityTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_runner.php';
    }

    #[Test]
    public function getReturnsBodyAndHeaders(): void
    {
        $response = $this->rawRequest("GET /ok HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertStringContainsStringIgnoringCase('Content-Type: text/plain', $response);
        self::assertSame('OK', $this->bodyOf($response));
    }

    #[Test]
    public function postBodyRoundTrips(): void
    {
        $body = 'hello=world&n=42';
        $response = $this->rawRequest(
            "POST /echo-body HTTP/1.1\r\nHost: x\r\nContent-Type: application/x-www-form-urlencoded\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\nConnection: close\r\n\r\n" . $body,
        );

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertSame($body, $this->bodyOf($response), 'the raw POST body should reach the handler');
    }

    #[Test]
    public function queryHeaderAndCookieReachTheHandler(): void
    {
        $response = $this->rawRequest(
            "GET /echo-request?name=bob HTTP/1.1\r\nHost: x\r\nX-Test: abc\r\nCookie: sid=xyz\r\nConnection: close\r\n\r\n",
        );

        self::assertSame('m=GET;q=bob;h=abc;c=xyz', $this->bodyOf($response));
    }

    #[Test]
    public function customStatusAndHeaderArePreserved(): void
    {
        $response = $this->rawRequest("GET /created HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('201', $this->statusLine($response));
        self::assertMatchesRegularExpression('/X-Request-Id:\s*r-123/i', $response);
        self::assertSame('made', $this->bodyOf($response));
    }
}
