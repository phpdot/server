<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit\Converter;

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Converter\RequestConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for RequestConverter::assembleRequest (the pure, array-in
 * assembly step — no Swoole needed).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class RequestConverterTest extends TestCase
{
    #[Test]
    public function assembleRequestMapsMethodUriHeadersQueryAndCookie(): void
    {
        $factory = new ResponseFactory();
        $converter = new RequestConverter($factory, $factory, $factory, $factory);

        $request = $converter->assembleRequest(
            headers: ['host' => 'example.com', 'x-test' => 'abc'],
            server: [
                'request_method' => 'GET',
                'request_uri' => '/path',
                'query_string' => 'name=bob',
                'server_protocol' => 'HTTP/1.1',
            ],
            cookies: ['sid' => 'xyz'],
            query: ['name' => 'bob'],
            post: null,
            files: [],
            body: '',
        );

        self::assertSame('GET', $request->getMethod());
        self::assertSame('http://example.com/path?name=bob', (string) $request->getUri());
        self::assertSame('1.1', $request->getProtocolVersion());
        self::assertSame('abc', $request->getHeaderLine('X-Test'));
        self::assertSame('xyz', $request->getCookieParams()['sid'] ?? '');
        self::assertSame('bob', $request->getQueryParams()['name'] ?? '');
    }

    #[Test]
    public function assembleRequestCarriesBodyAndParsedPost(): void
    {
        $factory = new ResponseFactory();
        $converter = new RequestConverter($factory, $factory, $factory, $factory);

        $request = $converter->assembleRequest(
            headers: ['host' => 'x'],
            server: ['request_method' => 'POST', 'request_uri' => '/'],
            cookies: [],
            query: [],
            post: ['email' => 'a@b.c'],
            files: [],
            body: 'raw-body',
        );

        self::assertSame('POST', $request->getMethod());
        self::assertSame('raw-body', (string) $request->getBody());
        self::assertSame('a@b.c', $request->getParsedBody()['email'] ?? '');
    }
}
