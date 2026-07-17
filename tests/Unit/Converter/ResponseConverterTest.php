<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit\Converter;

use PHPdot\Server\Converter\ResponseConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for ResponseConverter::parseCookieHeader (pure string-in,
 * array-out — no Swoole needed).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class ResponseConverterTest extends TestCase
{
    #[Test]
    public function parsesNameValueAndAttributes(): void
    {
        $parsed = new ResponseConverter()->parseCookieHeader(
            'sid=abc123; Path=/app; Domain=example.com; Secure; HttpOnly; SameSite=Lax; Partitioned',
        );

        self::assertSame('sid', $parsed['name']);
        self::assertSame('abc123', $parsed['value']);
        self::assertSame('/app', $parsed['path']);
        self::assertSame('example.com', $parsed['domain']);
        self::assertTrue($parsed['secure']);
        self::assertTrue($parsed['httpOnly']);
        self::assertSame('Lax', $parsed['sameSite']);
        self::assertTrue($parsed['partitioned']);
    }

    #[Test]
    public function maxAgeBeatsExpiresRegardlessOfAttributeOrder(): void
    {
        $converter = new ResponseConverter();
        $expiresValue = 'Wed, 21 Oct 2015 07:28:00 GMT';

        // RFC 6265 §5.3: when both are present, Max-Age wins.
        $maxAgeFirst = $converter->parseCookieHeader("a=b; Max-Age=100; Expires={$expiresValue}");
        $expiresFirst = $converter->parseCookieHeader("a=b; Expires={$expiresValue}; Max-Age=100");

        self::assertEqualsWithDelta(time() + 100, $maxAgeFirst['expires'], 5);
        self::assertEqualsWithDelta(time() + 100, $expiresFirst['expires'], 5);
    }

    #[Test]
    public function expiresAloneStillParses(): void
    {
        $parsed = new ResponseConverter()->parseCookieHeader('a=b; Expires=Wed, 21 Oct 2015 07:28:00 GMT');

        self::assertSame(strtotime('Wed, 21 Oct 2015 07:28:00 GMT'), $parsed['expires']);
    }

    #[Test]
    public function maxAgeAloneStillParses(): void
    {
        $parsed = new ResponseConverter()->parseCookieHeader('a=b; Max-Age=60');

        self::assertEqualsWithDelta(time() + 60, $parsed['expires'], 5);
    }

    #[Test]
    public function bareNameAndEmptyHeaderAreHandled(): void
    {
        $converter = new ResponseConverter();

        self::assertSame('flag', $converter->parseCookieHeader('flag')['name']);
        self::assertSame('', $converter->parseCookieHeader('')['name']);
    }
}
