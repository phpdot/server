<?php

declare(strict_types=1);

/**
 * RequestConverter — builds a PSR-7 ServerRequestInterface from a Swoole request.
 *
 * Split into extraction and assembly steps so the assembly logic can be tested
 * with plain arrays.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Converter;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Message\UriInterface;
use Swoole\Http\Request as SwooleRequest;

final class RequestConverter
{
    /**
     * Create the converter over the PSR-17 factories used to build the request.
     *
     * @param ServerRequestFactoryInterface $serverRequestFactory
     * @param UriFactoryInterface $uriFactory
     * @param StreamFactoryInterface $streamFactory
     * @param UploadedFileFactoryInterface $uploadedFileFactory
     */
    public function __construct(
        private readonly ServerRequestFactoryInterface $serverRequestFactory,
        private readonly UriFactoryInterface $uriFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UploadedFileFactoryInterface $uploadedFileFactory,
    ) {}

    /**
     * Convert a Swoole HTTP request to a PSR-7 server request.
     *
     * @param \Swoole\Http\Request $swooleRequest
     *
     * @return ServerRequestInterface
     */
    public function toServerRequest(SwooleRequest $swooleRequest): ServerRequestInterface
    {
        /**
         * @var array<string, string> $headers
         */
        $headers = $swooleRequest->header ?? [];
        /**
         * @var array<string, string> $server
         */
        $server = $swooleRequest->server ?? [];
        /**
         * @var array<string, string> $cookies
         */
        $cookies = $swooleRequest->cookie ?? [];
        /**
         * @var array<string, mixed> $query
         */
        $query = $swooleRequest->get ?? [];
        /**
         * @var array<string, mixed>|null $post
         */
        $post = $swooleRequest->post;
        /**
         * @var array<string, mixed> $files
         */
        $files = $swooleRequest->files ?? [];
        $rawContent = $swooleRequest->rawContent();
        $body = $rawContent === false ? '' : $rawContent;

        return $this->assembleRequest($headers, $server, $cookies, $query, $post, $files, $body);
    }

    /**
     * Assemble a PSR-7 request from raw arrays. Public for testing.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     * @param array<string, mixed> $query
     * @param array<string, mixed>|null $post
     * @param array<string, mixed> $files
     * @param string $body
     *
     * @return ServerRequestInterface
     */
    public function assembleRequest(
        array $headers,
        array $server,
        array $cookies,
        array $query,
        array|null $post,
        array $files,
        string $body,
    ): ServerRequestInterface {
        $uri = $this->buildUri($headers, $server);
        $serverParams = $this->buildServerParams($headers, $server);

        $method = strtoupper($server['request_method'] ?? 'GET');
        $request = $this->serverRequestFactory->createServerRequest($method, $uri, $serverParams);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $protocol = $server['server_protocol'] ?? 'HTTP/1.1';
        $request = $request->withProtocolVersion(str_replace('HTTP/', '', $protocol));

        $request = $request->withCookieParams($cookies);
        $request = $request->withQueryParams($query);
        $request = $request->withBody($this->streamFactory->createStream($body));

        if ($post !== null) {
            $request = $request->withParsedBody($post);
        }

        $normalizedFiles = $this->normalizeFiles($files);
        if ($normalizedFiles !== []) {
            $request = $request->withUploadedFiles($normalizedFiles);
        }

        return $request;
    }

    /**
     * Build the request URI from the headers and Swoole server params.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $server
     *
     * @return UriInterface
     */
    private function buildUri(array $headers, array $server): UriInterface
    {
        $scheme = $this->resolveScheme($headers, $server);
        $host = $this->resolveHost($headers, $server, $scheme);
        $path = explode('?', $server['request_uri'] ?? '/')[0];
        $queryString = $server['query_string'] ?? '';

        $uriString = $scheme . '://' . $host . $path;
        if ($queryString !== '') {
            $uriString .= '?' . $queryString;
        }

        return $this->uriFactory->createUri($uriString);
    }

    /**
     * Resolve the request scheme. Honors X-Forwarded-Proto (set by a trusting
     * reverse proxy / TLS terminator) so proxied HTTPS requests report `https`.
     * Native SSL is not detected here — deploy behind a proxy that sets the header.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $server
     *
     * @return string
     */
    private function resolveScheme(array $headers, array $server): string
    {
        $forwarded = strtolower(trim($headers['x-forwarded-proto'] ?? ''));
        if ($forwarded === 'https' || $forwarded === 'http') {
            return $forwarded;
        }

        return (isset($server['https']) && $server['https'] === 'on') ? 'https' : 'http';
    }

    /**
     * Resolve the authority (host[:port]). Falls back to the bind address; strips
     * the default port; and rejects header-injection attempts in the Host value.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $server
     * @param string $scheme
     *
     * @return string
     */
    private function resolveHost(array $headers, array $server, string $scheme): string
    {
        $host = $headers['host'] ?? '';

        if ($host === '') {
            $addr = $server['server_addr'] ?? 'localhost';
            $port = (int) ($server['server_port'] ?? 0);
            $isDefaultPort = ($scheme === 'http' && $port === 80)
                || ($scheme === 'https' && $port === 443);
            $host = ($port !== 0 && !$isDefaultPort) ? $addr . ':' . $port : $addr;
        }

        if (preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
            $host = $server['server_addr'] ?? 'localhost';
        }

        return $host;
    }

    /**
     * Build the PSR-7 server-params array from headers and Swoole data.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $server
     *
     * @return array<string, string>
     */
    private function buildServerParams(array $headers, array $server): array
    {
        $serverParams = [];
        foreach ($server as $key => $value) {
            $serverParams[strtoupper($key)] = $value;
        }

        foreach ($headers as $name => $value) {
            $upperName = strtoupper(str_replace('-', '_', $name));
            if ($upperName === 'CONTENT_TYPE' || $upperName === 'CONTENT_LENGTH') {
                $serverParams[$upperName] = $value;
            } else {
                $serverParams['HTTP_' . $upperName] = $value;
            }
        }

        return $serverParams;
    }

    /**
     * Normalize Swoole's files array into a PSR-7 uploaded-files tree.
     *
     * Swoole 6 emits two shapes (ext-src/swoole_http_request.cc): with
     * http_parse_files enabled, bracketed field names ("f[]", "f[a][b]") arrive
     * PHP-style transposed — tmp_name/size/error/name/type each holding a
     * parallel (possibly nested) array; plain names, and every name when
     * http_parse_files is off, arrive as one complete
     * {tmp_name, size, error, name, type} entry per leaf. Both shapes
     * normalize here, recursively, to any depth.
     *
     * @param array<array-key, mixed> $files
     *
     * @return array<array-key, UploadedFileInterface|array<array-key, mixed>>
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $file) {
            if (!is_array($file)) {
                continue;
            }

            if (isset($file['tmp_name'])) {
                /**
                 * @var array<string, mixed> $spec
                 */
                $spec = $file;
                $normalized[$key] = is_array($spec['tmp_name'])
                    ? $this->normalizeTransposed($spec)
                    : $this->createUploadedFile($spec);

                continue;
            }

            $nested = $this->normalizeFiles($file);
            if ($nested !== []) {
                $normalized[$key] = $nested;
            }
        }
        return $normalized;
    }

    /**
     * Expand one level of a PHP-style transposed spec (tmp_name/size/error/
     * name/type as parallel arrays), recursing until each tmp_name is a leaf.
     *
     * @param array<string, mixed> $spec
     *
     * @return array<array-key, UploadedFileInterface|array<array-key, mixed>>
     */
    private function normalizeTransposed(array $spec): array
    {
        $files = [];
        /**
         * @var array<array-key, mixed> $tmpNames
         */
        $tmpNames = $spec['tmp_name'];
        $sizes = is_array($spec['size'] ?? null) ? $spec['size'] : [];
        $errors = is_array($spec['error'] ?? null) ? $spec['error'] : [];
        $names = is_array($spec['name'] ?? null) ? $spec['name'] : [];
        $types = is_array($spec['type'] ?? null) ? $spec['type'] : [];

        foreach (array_keys($tmpNames) as $index) {
            $leaf = [
                'tmp_name' => $tmpNames[$index],
                'size' => $sizes[$index] ?? 0,
                'error' => $errors[$index] ?? UPLOAD_ERR_NO_FILE,
                'name' => $names[$index] ?? '',
                'type' => $types[$index] ?? '',
            ];

            $files[$index] = is_array($leaf['tmp_name'])
                ? $this->normalizeTransposed($leaf)
                : $this->createUploadedFile($leaf);
        }
        return $files;
    }

    /**
     * Create one PSR-7 uploaded file from a Swoole file spec.
     *
     * @param array<string, mixed> $file
     *
     * @return UploadedFileInterface
     */
    private function createUploadedFile(array $file): UploadedFileInterface
    {
        $tmpName = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
        $size = is_int($file['size'] ?? null) ? $file['size'] : 0;
        $error = is_int($file['error'] ?? null) ? $file['error'] : UPLOAD_ERR_OK;
        $name = is_string($file['name'] ?? null) ? $file['name'] : '';
        $type = is_string($file['type'] ?? null) ? $file['type'] : '';

        if ($error !== UPLOAD_ERR_OK || $tmpName === '') {
            $stream = $this->streamFactory->createStream('');
        } else {
            $stream = $this->streamFactory->createStreamFromFile($tmpName);
        }

        return $this->uploadedFileFactory->createUploadedFile($stream, $size, $error, $name, $type);
    }
}
