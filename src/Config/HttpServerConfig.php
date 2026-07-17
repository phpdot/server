<?php

declare(strict_types=1);

/**
 * HttpServerConfig — bind address, socket type, and HTTP-specific settings for
 * the HTTP socket (SSL, HTTP/2, static handler, compression, parsing toggles,
 * the "Server" response header). Hydrated from config/server/http.php via
 * #[Config('server.http')]; HttpServer owns it and contributes its toArray()
 * to the master set() as the primary transport.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Config;

use PHPdot\Container\Attribute\Config;

#[Config('server.http')]
final class HttpServerConfig
{
    /**
     * Create the HTTP server configuration.
     *
     * @param int $sockType SWOOLE_SOCK_TCP (add SWOOLE_SSL for TLS).
     * @param string $serverSoftware "Server" response header (empty = Swoole default).
     * @param bool $httpParsePost Auto-parse POST body.
     * @param bool $httpParseCookie Auto-parse cookies.
     * @param bool $httpParseFiles Auto-parse uploaded files.
     * @param bool $http2 Enable HTTP/2.
     * @param bool $httpCompression Enable HTTP compression.
     * @param int $httpCompressionLevel 1-9.
     * @param bool $staticHandler Enable static file serving.
     * @param list<string> $staticHandlerLocations
     * @param int $sslProtocols Bitmask (0 = Swoole default).
     * @param string $host
     * @param int $port
     * @param int $httpCompressionMinLength
     * @param string $uploadTmpDir
     * @param string $documentRoot
     * @param string $sslCertFile
     * @param string $sslKeyFile
     * @param string $sslCaFile
     * @param bool $sslVerifyPeer
     * @param string $sslCiphers
     */
    public function __construct(
        public readonly string $host = '0.0.0.0',
        public readonly int $port = 8080,
        public readonly int $sockType = SWOOLE_SOCK_TCP,
        public readonly string $serverSoftware = 'PHPdot Server',
        public readonly bool $httpParsePost = true,
        public readonly bool $httpParseCookie = true,
        public readonly bool $httpParseFiles = true,
        public readonly bool $http2 = false,
        public readonly bool $httpCompression = true,
        public readonly int $httpCompressionMinLength = 20,
        public readonly int $httpCompressionLevel = 1,
        public readonly string $uploadTmpDir = '/tmp',
        public readonly bool $staticHandler = false,
        public readonly string $documentRoot = '',
        public readonly array $staticHandlerLocations = [],
        public readonly string $sslCertFile = '',
        public readonly string $sslKeyFile = '',
        public readonly string $sslCaFile = '',
        public readonly bool $sslVerifyPeer = false,
        public readonly int $sslProtocols = 0,
        public readonly string $sslCiphers = '',
    ) {}

    /**
     * HTTP toggles merged into the master set() when HttpServer is the primary.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $s = [
            'http_parse_post' => $this->httpParsePost,
            'http_parse_cookie' => $this->httpParseCookie,
            'http_parse_files' => $this->httpParseFiles,
            'open_http2_protocol' => $this->http2,
            'http_compression' => $this->httpCompression,
            'http_compression_min_length' => $this->httpCompressionMinLength,
            'http_compression_level' => $this->httpCompressionLevel,
            'upload_tmp_dir' => $this->uploadTmpDir,
            'enable_static_handler' => $this->staticHandler,
            'ssl_verify_peer' => $this->sslVerifyPeer,
        ];

        if ($this->documentRoot !== '') {
            $s['document_root'] = $this->documentRoot;
        }
        if ($this->staticHandlerLocations !== []) {
            $s['static_handler_locations'] = $this->staticHandlerLocations;
        }
        if ($this->sslCertFile !== '') {
            $s['ssl_cert_file'] = $this->sslCertFile;
        }
        if ($this->sslKeyFile !== '') {
            $s['ssl_key_file'] = $this->sslKeyFile;
        }
        if ($this->sslCaFile !== '') {
            $s['ssl_ca_file'] = $this->sslCaFile;
        }
        if ($this->sslProtocols > 0) {
            $s['ssl_protocols'] = $this->sslProtocols;
        }
        if ($this->sslCiphers !== '') {
            $s['ssl_ciphers'] = $this->sslCiphers;
        }

        return $s;
    }
}
