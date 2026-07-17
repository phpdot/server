<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;

/**
 * Multipart uploads through the real Swoole parser (http_parse_files on, the
 * package default) into the converter. Swoole emits bracketed field names in
 * PHP's transposed shape (ext-src/swoole_http_request.cc), so simple, array
 * ("f[]"), and nested ("f[a][b]") names all have to normalize into a PSR-7
 * uploaded-files tree — nested names used to crash the converter with a
 * TypeError (500).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class UploadTest extends ServerTestCase
{
    protected function runnerScript(): string
    {
        return __DIR__ . '/Fixtures/server_upload_runner.php';
    }

    #[Test]
    public function simpleFieldUploadArrives(): void
    {
        $response = $this->postMultipart([['f', 'up.txt', 'hello']]);

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertSame(['f' => 'file:up.txt:5'], $this->shapeOf($response));
    }

    #[Test]
    public function arrayFieldUploadsArriveAsList(): void
    {
        $response = $this->postMultipart([
            ['f[]', 'a.txt', 'aaaa'],
            ['f[]', 'b.txt', 'bb'],
        ]);

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertSame(['f' => ['file:a.txt:4', 'file:b.txt:2']], $this->shapeOf($response));
    }

    #[Test]
    public function nestedFieldNameUploadArrivesAsTree(): void
    {
        $response = $this->postMultipart([['f[a][b]', 'deep.txt', 'deep']]);

        self::assertStringContainsString('200', $this->statusLine($response), 'nested field names used to 500 with a TypeError');
        self::assertSame(['f' => ['a' => ['b' => 'file:deep.txt:4']]], $this->shapeOf($response));
    }

    #[Test]
    public function mixedSimpleAndNestedFieldsCoexist(): void
    {
        $response = $this->postMultipart([
            ['plain', 'p.txt', 'p'],
            ['f[x]', 'x.txt', 'xx'],
        ]);

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertSame(
            ['plain' => 'file:p.txt:1', 'f' => ['x' => 'file:x.txt:2']],
            $this->shapeOf($response),
        );
    }

    /**
     * POST a multipart/form-data body built from [fieldName, filename, content] parts.
     *
     * @param list<array{0: string, 1: string, 2: string}> $parts
     */
    private function postMultipart(array $parts): string
    {
        $boundary = 'PHPDOT' . bin2hex(random_bytes(8));

        $body = '';
        foreach ($parts as [$name, $filename, $content]) {
            $body .= "--{$boundary}\r\n"
                . "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: text/plain\r\n\r\n"
                . $content . "\r\n";
        }
        $body .= "--{$boundary}--\r\n";

        return $this->rawRequest(
            "POST / HTTP/1.1\r\nHost: x\r\nConnection: close\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n\r\n"
            . $body,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function shapeOf(string $response): array
    {
        $decoded = json_decode($this->bodyOf($response), true);
        self::assertIsArray($decoded, 'runner should return a JSON shape, got: ' . $this->bodyOf($response));

        return $decoded;
    }
}
