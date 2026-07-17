<?php

declare(strict_types=1);

/**
 * ResponseConverter — writes a PSR-7 ResponseInterface onto a Swoole response.
 *
 * Picks the most efficient body strategy: StreamedResponse callback, callback
 * stream, sendfile (with range), empty, chunked (large/unknown size), or direct
 * end() for small bodies. Strips Content-Length when the body will stream so it
 * does not conflict with Swoole's Transfer-Encoding: chunked.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Converter;

use PHPdot\Http\Response\StreamedResponseInterface;
use PHPdot\Server\Contract\CallbackStreamInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Http\Response as SwooleResponse;

final class ResponseConverter
{
    /**
     * Create the response converter with its server-software default.
     *
     * @param int $chunkSize Maximum chunk size in bytes for large responses.
     * @param string $serverSoftware Default "Server" header when the response sets
     *                               none (empty = Swoole default).
     */
    public function __construct(
        private readonly int $chunkSize = 1048576,
        private readonly string $serverSoftware = '',
    ) {}

    /**
     * Write a PSR-7 response to a Swoole response.
     *
     * @param bool $omitBody Emit status + headers only (HEAD requests).
     * @param bool $started Set true once the body begins streaming; lets the caller
     *                      detect a mid-stream failure that can no longer change the status.
     * @param ResponseInterface $psrResponse
     * @param SwooleResponse $swooleResponse
     *
     * @return void
     */
    public function toSwoole(
        ResponseInterface $psrResponse,
        SwooleResponse $swooleResponse,
        bool $omitBody = false,
        bool &$started = false,
    ): void {
        $swooleResponse->status($psrResponse->getStatusCode(), $psrResponse->getReasonPhrase());

        $trailerNames = [];
        if ($psrResponse->hasHeader('Trailer')) {
            $trailerNames = array_map(
                static fn(string $name): string => strtolower(trim($name)),
                explode(',', $psrResponse->getHeaderLine('Trailer')),
            );
        }

        $stripContentLength = !$omitBody && $this->willStream($psrResponse);

        foreach ($psrResponse->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if ($lower === 'set-cookie' || $lower === 'transfer-encoding') {
                continue;
            }
            if ($lower === 'content-length' && $stripContentLength) {
                continue;
            }
            if (in_array($lower, $trailerNames, true)) {
                continue;
            }
            $swooleResponse->header($name, $values);
        }

        if ($this->serverSoftware !== '' && !$psrResponse->hasHeader('Server')) {
            $swooleResponse->header('Server', $this->serverSoftware);
        }

        $this->emitCookies($psrResponse, $swooleResponse);
        $this->emitTrailers($psrResponse, $swooleResponse, $trailerNames);

        if ($omitBody) {
            $swooleResponse->end();
            return;
        }

        $this->emitBody($psrResponse, $swooleResponse, $started);
    }

    /**
     * Parse a Set-Cookie header string into its components.
     *
     * @param string $header
     *
     * @return array{
     *     name: string,
     *     value: string,
     *     expires: int,
     *     path: string,
     *     domain: string,
     *     secure: bool,
     *     httpOnly: bool,
     *     sameSite: string,
     *     partitioned: bool
     * }
     */
    public function parseCookieHeader(string $header): array
    {
        $result = [
            'name' => '',
            'value' => '',
            'expires' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => false,
            'httpOnly' => false,
            'sameSite' => '',
            'partitioned' => false,
        ];

        if ($header === '') {
            return $result;
        }

        $parts = explode(';', $header);
        $firstPart = array_shift($parts);
        $sawMaxAge = false;

        $equalPos = strpos($firstPart, '=');
        if ($equalPos === false) {
            $result['name'] = trim($firstPart);
        } else {
            $result['name'] = trim(substr($firstPart, 0, $equalPos));
            $result['value'] = trim(substr($firstPart, $equalPos + 1));
        }

        foreach ($parts as $part) {
            $part = trim($part);
            $lowerPart = strtolower($part);

            if ($lowerPart === 'secure') {
                $result['secure'] = true;
                continue;
            }
            if ($lowerPart === 'httponly') {
                $result['httpOnly'] = true;
                continue;
            }
            if ($lowerPart === 'partitioned') {
                $result['partitioned'] = true;
                continue;
            }

            $attrEqualPos = strpos($part, '=');
            if ($attrEqualPos === false) {
                continue;
            }

            $attrName = strtolower(trim(substr($part, 0, $attrEqualPos)));
            $attrValue = trim(substr($part, $attrEqualPos + 1));

            switch ($attrName) {
                case 'expires':
                    if (!$sawMaxAge) {
                        $timestamp = strtotime($attrValue);
                        $result['expires'] = $timestamp !== false ? $timestamp : 0;
                    }
                    break;
                case 'max-age':
                    $sawMaxAge = true;
                    $result['expires'] = time() + (int) $attrValue;
                    break;
                case 'path':
                    $result['path'] = $attrValue;
                    break;
                case 'domain':
                    $result['domain'] = $attrValue;
                    break;
                case 'samesite':
                    $result['sameSite'] = $attrValue;
                    break;
            }
        }

        return $result;
    }

    /**
     * Will stream.
     *
     * @param ResponseInterface $psrResponse
     *
     * @return bool
     */
    private function willStream(ResponseInterface $psrResponse): bool
    {
        if ($psrResponse instanceof StreamedResponseInterface) {
            return true;
        }

        $body = $psrResponse->getBody();
        if ($body instanceof CallbackStreamInterface) {
            return true;
        }

        $meta = $body->getMetadata();
        if (
            is_array($meta)
            && ($meta['wrapper_type'] ?? '') === 'plainfile'
            && isset($meta['uri'])
            && is_string($meta['uri'])
            && is_file($meta['uri'])
        ) {
            return false;
        }

        $size = $body->getSize();
        return $size === null || $size > $this->chunkSize;
    }

    /**
     * Emit cookies.
     *
     * @param ResponseInterface $psrResponse
     * @param SwooleResponse $swooleResponse
     *
     * @return void
     */
    private function emitCookies(ResponseInterface $psrResponse, SwooleResponse $swooleResponse): void
    {
        $cookieHeaders = $psrResponse->getHeader('Set-Cookie');
        foreach ($cookieHeaders as $cookieHeader) {
            $parsed = $this->parseCookieHeader($cookieHeader);
            $swooleResponse->rawcookie(
                $parsed['name'],
                $parsed['value'],
                $parsed['expires'],
                $parsed['path'],
                $parsed['domain'],
                $parsed['secure'],
                $parsed['httpOnly'],
                $parsed['sameSite'],
                '',
                $parsed['partitioned'],
            );
        }
    }

    /**
     * Emit any HTTP trailers onto the Swoole response.
     *
     * @param list<string> $trailerNames
     * @param ResponseInterface $psrResponse
     * @param \Swoole\Http\Response $swooleResponse
     *
     * @return void
     */
    private function emitTrailers(ResponseInterface $psrResponse, SwooleResponse $swooleResponse, array $trailerNames): void
    {
        foreach ($trailerNames as $trailerName) {
            if ($psrResponse->hasHeader($trailerName)) {
                $swooleResponse->trailer($trailerName, $psrResponse->getHeaderLine($trailerName));
            }
        }
    }

    /**
     * Emit body.
     *
     * @param ResponseInterface $psrResponse
     * @param SwooleResponse $swooleResponse
     * @param bool $started
     *
     * @return void
     */
    private function emitBody(ResponseInterface $psrResponse, SwooleResponse $swooleResponse, bool &$started): void
    {
        if ($psrResponse instanceof StreamedResponseInterface) {
            $psrResponse->emit(static function (string $chunk) use ($swooleResponse, &$started): bool {
                $started = true;
                return $swooleResponse->write($chunk);
            });
            $swooleResponse->end();
            return;
        }

        $body = $psrResponse->getBody();

        if ($body instanceof CallbackStreamInterface) {
            $callback = $body->getCallback();
            $callback(static function (string $chunk) use ($swooleResponse, &$started): void {
                $started = true;
                $swooleResponse->write($chunk);
            });
            $swooleResponse->end();
            return;
        }

        $meta = $body->getMetadata();
        if (
            is_array($meta)
            && ($meta['wrapper_type'] ?? '') === 'plainfile'
            && isset($meta['uri'])
            && is_string($meta['uri'])
            && is_file($meta['uri'])
        ) {
            $offset = 0;
            $length = 0;

            if ($psrResponse->hasHeader('Content-Range')) {
                $range = $psrResponse->getHeaderLine('Content-Range');
                if (preg_match('/bytes\s+(\d+)-(\d+)/', $range, $matches) === 1) {
                    $offset = (int) $matches[1];
                    $length = (int) $matches[2] - $offset + 1;
                }
            }

            $swooleResponse->sendfile($meta['uri'], $offset, $length);
            return;
        }

        $size = $body->getSize();
        if ($size === 0) {
            $swooleResponse->end();
            return;
        }

        if ($size === null || $size > $this->chunkSize) {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read($this->chunkSize);
                if ($chunk !== '') {
                    $started = true;
                    if ($swooleResponse->write($chunk) === false) {
                        break;
                    }
                }
            }
            $swooleResponse->end();
            return;
        }

        $content = (string) $body;
        if ($content === '') {
            $swooleResponse->end();
            return;
        }

        $swooleResponse->end($content);
    }
}
