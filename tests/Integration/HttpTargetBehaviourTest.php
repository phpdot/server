<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP wire-behaviour end to end: a forwarded scheme from a trusted proxy must
 * reach the request, and repeated response header values must go on the wire as
 * separate lines rather than comma-folded. Boots a real server and asserts the
 * exact bytes on the socket.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class HttpTargetBehaviourTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_runner.php';
    }

    /**
     * X-Forwarded-Proto from a trusted proxy must set the request scheme
     * (127.0.0.1 is the trusted peer in this test).
     */
    #[Test]
    public function forwardedProtoSetsTheScheme(): void
    {
        $response = $this->rawRequest(
            "GET /scheme HTTP/1.1\r\nHost: x\r\nX-Forwarded-Proto: https\r\nConnection: close\r\n\r\n",
        );

        self::assertSame(
            'https',
            $this->bodyOf($response),
            'the request scheme must reflect X-Forwarded-Proto behind a trusted proxy',
        );
    }

    /**
     * Repeated header values must be emitted as separate lines, not comma-folded.
     */
    #[Test]
    public function multiValueHeadersAreNotCommaFolded(): void
    {
        $response = $this->rawRequest("GET /www-auth HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('401', $this->statusLine($response));
        self::assertSame(
            2,
            preg_match_all('/^WWW-Authenticate:/mi', $response),
            'multiple WWW-Authenticate values must be separate header lines, not comma-folded',
        );
    }
}
