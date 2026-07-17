<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Parity harness — characterizes the CURRENT correct behaviour of the HTTP
 * response path (ResponseConverter) at the byte level, so the SR-M2 clean-slate
 * rewrite can be proven behaviour-preserving. These lock CORRECT behaviour only;
 * known-buggy paths (SSL scheme, forwarded headers, trailers, exception leak)
 * get dedicated SR-M2 target tests, not parity locks.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class HttpConverterParityTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_runner.php';
    }

    #[Test]
    public function setCookieHeaderReachesTheWire(): void
    {
        $response = $this->rawRequest("GET /set-cookie HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertMatchesRegularExpression('/Set-Cookie:\s*session=abc123/i', $response);
        self::assertSame('cookie-set', $this->bodyOf($response));
    }

    #[Test]
    public function multipleResponseHeadersArePreserved(): void
    {
        $response = $this->rawRequest("GET /multi-header HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertMatchesRegularExpression('/X-Alpha:\s*1/i', $response);
        self::assertMatchesRegularExpression('/X-Beta:\s*2/i', $response);
        self::assertSame('multi', $this->bodyOf($response));
    }

    #[Test]
    public function notFoundStatusIsPreserved(): void
    {
        $response = $this->rawRequest("GET /status-404 HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('404', $this->statusLine($response));
        self::assertSame('nope', $this->bodyOf($response));
    }

    #[Test]
    public function contentLengthMatchesTheBody(): void
    {
        $response = $this->rawRequest("GET /len HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertMatchesRegularExpression('/Content-Length:\s*5/i', $response);
        self::assertSame('abcde', $this->bodyOf($response));
    }

    #[Test]
    public function jsonContentTypeIsPreserved(): void
    {
        $response = $this->rawRequest("GET /json HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsStringIgnoringCase('Content-Type: application/json', $response);
        self::assertSame('{"ok":true}', $this->bodyOf($response));
    }

    #[Test]
    public function noContentResponseHasEmptyBody(): void
    {
        $response = $this->rawRequest("GET /no-content HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('204', $this->statusLine($response));
        self::assertSame('', $this->bodyOf($response));
    }

    #[Test]
    public function largeBodyRoundTripsIntact(): void
    {
        $expected = str_repeat('0123456789', 20000); // 200 KB

        $response = $this->rawRequest("GET /big HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        self::assertStringContainsString('200', $this->statusLine($response));

        $body = $this->bodyOf($response);
        if (stripos($response, 'Transfer-Encoding: chunked') !== false) {
            $body = $this->dechunk($body);
        }

        self::assertSame(strlen($expected), strlen($body), 'the full body length must survive the wire');
        self::assertSame(md5($expected), md5($body), 'the body content must survive intact');
    }

    #[Test]
    public function serverErrorPassesTheMessageThroughByDesign(): void
    {
        // The last-resort 500 leaks the raw exception message ON PURPOSE — the app's
        // phpdot/error-handler middleware sanitizes exceptions upstream. Locked so a
        // future change can't silently break that contract.
        $response = $this->rawRequest("GET /boom HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('500', $this->statusLine($response));
        self::assertStringContainsString('SECRET_LEAK_TOKEN_9f3a', $response, 'the raw message passes through by design');
    }

    #[Test]
    public function defaultSchemeIsHttpWithoutForwardedHeader(): void
    {
        $response = $this->rawRequest("GET /scheme HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertSame('http', $this->bodyOf($response), 'the scheme must default to http with no X-Forwarded-Proto');
    }

    #[Test]
    public function headRequestReturnsNoBody(): void
    {
        $response = $this->rawRequest("HEAD /len HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertSame('', $this->bodyOf($response), 'a HEAD response must not carry a body');
    }

    /**
     * Decode an HTTP/1.1 chunked-transfer body into its raw bytes.
     */
    private function dechunk(string $body): string
    {
        $out = '';
        $offset = 0;
        $len = strlen($body);

        while ($offset < $len) {
            $crlf = strpos($body, "\r\n", $offset);
            if ($crlf === false) {
                break;
            }

            $size = (int) hexdec(trim(substr($body, $offset, $crlf - $offset)));
            if ($size === 0) {
                break;
            }

            $offset = $crlf + 2;
            $out .= substr($body, $offset, $size);
            $offset += $size + 2; // data + trailing CRLF
        }

        return $out;
    }
}
