<?php

declare(strict_types=1);

namespace PHPdot\Server\Tests\Unit\Converter;

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Converter\RequestConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Unit coverage for uploaded-file normalization: both shapes Swoole 6 emits
 * (ext-src/swoole_http_request.cc) must produce a PSR-7 uploaded-files tree.
 * With http_parse_files ON, bracketed names arrive PHP-style TRANSPOSED
 * (tmp_name/size/... as parallel arrays, nested to any depth); with it OFF —
 * and always for plain names — each leaf is one complete file entry.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
final class RequestConverterUploadsTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @unlink($file);
        }
        $this->tmpFiles = [];
    }

    #[Test]
    public function simpleLeafEntryBecomesAnUploadedFile(): void
    {
        $tmp = $this->tmpFile('hello');

        $files = $this->normalize([
            'f' => ['name' => 'up.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => 0, 'size' => 5],
        ]);

        $file = $files['f'] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $file);
        self::assertSame('up.txt', $file->getClientFilename());
        self::assertSame(5, $file->getSize());
        self::assertSame('hello', (string) $file->getStream());
    }

    #[Test]
    public function transposedFlatListNormalizesToAListOfFiles(): void
    {
        $a = $this->tmpFile('aaaa');
        $b = $this->tmpFile('bb');

        // Swoole shape for `f[]` + `f[]` with http_parse_files on.
        $files = $this->normalize([
            'f' => [
                'name' => ['a.txt', 'b.txt'],
                'type' => ['text/plain', 'text/plain'],
                'tmp_name' => [$a, $b],
                'error' => [0, 0],
                'size' => [4, 2],
            ],
        ]);

        self::assertIsArray($files['f'] ?? null);
        self::assertCount(2, $files['f']);
        self::assertInstanceOf(UploadedFileInterface::class, $files['f'][0]);
        self::assertInstanceOf(UploadedFileInterface::class, $files['f'][1]);
        self::assertSame('a.txt', $files['f'][0]->getClientFilename());
        self::assertSame(2, $files['f'][1]->getSize());
    }

    #[Test]
    public function transposedNestedSpecNormalizesToATree(): void
    {
        $tmp = $this->tmpFile('deep');

        // Swoole shape for `f[a][b]` with http_parse_files on — this exact
        // structure used to crash createUploadedFile with an array $size.
        $files = $this->normalize([
            'f' => [
                'name' => ['a' => ['b' => 'deep.txt']],
                'type' => ['a' => ['b' => 'text/plain']],
                'tmp_name' => ['a' => ['b' => $tmp]],
                'error' => ['a' => ['b' => 0]],
                'size' => ['a' => ['b' => 4]],
            ],
        ]);

        $leaf = $files['f']['a']['b'] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $leaf);
        self::assertSame('deep.txt', $leaf->getClientFilename());
        self::assertSame(4, $leaf->getSize());
    }

    #[Test]
    public function naturalNestedLeafEntriesNormalizeToATree(): void
    {
        $tmp = $this->tmpFile('deep');

        // Swoole shape for `f[a][b]` with http_parse_files OFF: complete leaf dicts.
        $files = $this->normalize([
            'f' => [
                'a' => [
                    'b' => ['name' => 'deep.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => 0, 'size' => 4],
                ],
            ],
        ]);

        $leaf = $files['f']['a']['b'] ?? null;
        self::assertInstanceOf(UploadedFileInterface::class, $leaf);
        self::assertSame('deep.txt', $leaf->getClientFilename());
    }

    #[Test]
    public function malformedEntriesAreDroppedNotFatal(): void
    {
        $files = $this->normalize([
            'scalar' => 'not-a-file',
            'empty' => [],
            'errored' => ['name' => 'x.bin', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]);

        self::assertArrayNotHasKey('scalar', $files);
        self::assertArrayNotHasKey('empty', $files);
        // An errored upload is still a PSR-7 uploaded file (the app inspects getError()).
        self::assertInstanceOf(UploadedFileInterface::class, $files['errored'] ?? null);
        self::assertSame(UPLOAD_ERR_NO_FILE, $files['errored']->getError());
    }

    /**
     * @param array<string, mixed> $files
     * @return array<array-key, mixed>
     */
    private function normalize(array $files): array
    {
        $factory = new ResponseFactory();
        $converter = new RequestConverter($factory, $factory, $factory, $factory);

        $request = $converter->assembleRequest(
            headers: ['host' => 'x'],
            server: ['request_method' => 'POST', 'request_uri' => '/'],
            cookies: [],
            query: [],
            post: null,
            files: $files,
            body: '',
        );

        return $request->getUploadedFiles();
    }

    private function tmpFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phpdot_upload_');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;

        return $path;
    }
}
