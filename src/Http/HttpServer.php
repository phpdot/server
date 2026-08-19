<?php

declare(strict_types=1);

/**
 * HttpServer — the HTTP transport (attachable, always primary). One socket
 * carrying plain HTTP, WebSocket upgrades, and SSE responses — all three ride
 * HTTP, so they are handlers branched in here, not separate transports.
 *
 * Branching by handler capability + request shape:
 * - WebSocketHandlerInterface → the runner builds a Swoole\WebSocket\Server
 * (the upgrade handshake is Swoole's); open/message/close are wired here.
 * - SseHandlerInterface + `Accept: text/event-stream` → streams via handleSse().
 * - otherwise → the PSR-15 request pipeline.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Server\Http;

use PHPdot\Contracts\Server\SseHandlerInterface;
use PHPdot\Contracts\Server\WebSocketHandlerInterface;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Contract\OnWorkerExitInterface;
use PHPdot\Server\Contract\Transport;
use PHPdot\Server\Converter\RequestConverter;
use PHPdot\Server\Converter\ResponseConverter;
use PHPdot\Server\Exception\ServerException;
use PHPdot\Server\Server;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Server as SwooleServer;
use Swoole\WebSocket\Frame as SwooleFrame;
use Swoole\WebSocket\Server as WebSocketServer;

final class HttpServer implements Transport, OnWorkerExitInterface
{
    private readonly RequestConverter $requestConverter;

    private readonly ResponseConverter $responseConverter;

    /**
     * In-flight SSE coroutine IDs (per worker). Tracked so onWorkerExit can
     * cancel each stream before the reactor drain / force-kill — otherwise a
     * long-lived SSE Co::sleep loop pins the worker on recycle (ERRNO 9101).
     *
     * @var array<int, true>
     */
    private array $sseCoroutineIds = [];

    /**
     * Fds whose WS open the handler accepted (per worker; open/close for a fd
     * share a worker under fd-fixed dispatch). Swoole fires `close` for EVERY
     * connection on the port — plain HTTP requests that never upgraded included —
     * so handleWsClose only fires for fds recorded here. Status can't stand in
     * for this map: a server-initiated disconnect() ends at websocket_status 0
     * (websocket.cc — the CLOSING→0 reset), indistinguishable from an HTTP fd.
     *
     * @var array<int, true>
     */
    private array $wsAcceptedFds = [];

    /**
     * Fds with an HTTP request currently being handled (per worker). onWorkerExit
     * closes every OTHER connection — the idle keep-alive sockets that would
     * otherwise pin an exiting BASE worker until the max_wait_time force-kill
     * (ERRNO 9101), turning every reload into a multi-second stall.
     *
     * @var array<int, true>
     */
    private array $activeHttpFds = [];

    /**
     * Every open connection fd (per worker), maintained via the connect/close
     * events. The exit sweep needs its own ledger: in BASE mode the
     * Server::$connections iterator is PER-WORKER (each worker's connections
     * live on its own heap, not in shared memory) and its behavior during the
     * exit phase is unreliable, so the only list of a worker's sockets this
     * sweep can trust is the one the worker keeps itself.
     *
     * @var array<int, true>
     */
    private array $openFds = [];

    /**
     * Create the HTTP server over its config, handler, and PSR-17 factory.
     *
     * @param ServerRequestFactoryInterface&UriFactoryInterface&StreamFactoryInterface&UploadedFileFactoryInterface $factory
     * @param HttpServerConfig $config Bind address + HTTP-specific settings.
     */
    public function __construct(
        private readonly ServerRequestFactoryInterface&UriFactoryInterface&StreamFactoryInterface&UploadedFileFactoryInterface $factory,
        private readonly HttpServerConfig $config = new HttpServerConfig(),
    ) {
        $this->requestConverter = new RequestConverter($factory, $factory, $factory, $factory);
        $this->responseConverter = new ResponseConverter(
            serverSoftware: $config->serverSoftware,
            keepAlive: $config->keepAlive,
        );
    }

    public function host(): string
    {
        return $this->config->host;
    }

    public function port(): int
    {
        return $this->config->port;
    }

    public function sockType(): int
    {
        return $this->config->sockType;
    }

    /**
     * Transport settings contributed to the master set() (HTTP toggles).
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->config->toArray();
    }

    public function register(SwooleServer $master, bool $primary, object|null $handler): void
    {
        if (!$primary) {
            throw new ServerException('HttpServer cannot be an added port — HTTP must be the primary transport.');
        }

        if (!$handler instanceof RequestHandlerInterface) {
            throw new ServerException('HttpServer requires a PSR-15 RequestHandlerInterface (pass it to Server::serve()).');
        }

        $requestConverter = $this->requestConverter;
        $isSse = $handler instanceof SseHandlerInterface;

        /**
         * @var (\Closure(SwooleServer, int): void)|null $wsCloseHandler
         */
        $wsCloseHandler = null;

        if ($handler instanceof WebSocketHandlerInterface) {
            if (!$master instanceof WebSocketServer) {
                throw new ServerException('WebSocket handling requires a Swoole\WebSocket\Server master.');
            }

            $wsHandler = $handler;
            $ws = $master;

            $ws->on('open', function (WebSocketServer $server, SwooleRequest $req) use ($wsHandler, $requestConverter): void {
                $psr = $requestConverter->toServerRequest($req);
                $fd = $req->fd;

                $accepted = $wsHandler->handleWsOpen(
                    $fd,
                    $psr,
                    static fn(string $data): bool => $server->push($fd, $data),
                    static fn(string $data): bool => $server->push($fd, $data, WEBSOCKET_OPCODE_BINARY),
                    static fn(int $code, string $reason): bool => $server->disconnect($fd, $code, $reason),
                );

                if (!$accepted) {
                    $server->disconnect($fd);

                    return;
                }

                $this->wsAcceptedFds[$fd] = true;
            });

            $ws->on('message', static function (WebSocketServer $server, SwooleFrame $frame) use ($wsHandler): void {
                $fd = $frame->fd;
                $data = $frame->data;
                $opcode = $frame->opcode;

                if (!is_int($fd) || !is_string($data) || !is_int($opcode)) {
                    return;
                }

                $wsHandler->handleWsMessage($fd, $data, $opcode);
            });

            $wsCloseHandler = function (SwooleServer $server, int $fd) use ($wsHandler): void {
                $established = isset($this->wsAcceptedFds[$fd]);
                unset($this->wsAcceptedFds[$fd]);

                if (!$established) {
                    $info = $server->getClientInfo($fd);
                    $established = is_array($info)
                        && ($info['websocket_status'] ?? 0) === WEBSOCKET_STATUS_ACTIVE;
                }

                if ($established) {
                    $wsHandler->handleWsClose($fd, 1000, '');
                }
            };
        }

        $master->on('connect', function (SwooleServer $server, int $fd): void {
            $this->openFds[$fd] = true;
        });

        $master->on('close', function (SwooleServer $server, int $fd) use ($wsCloseHandler): void {
            unset($this->openFds[$fd]);

            if ($wsCloseHandler !== null) {
                $wsCloseHandler($server, $fd);
            }
        });

        $keepAlive = $this->config->keepAlive;

        $master->on('request', function (SwooleRequest $req, SwooleResponse $resp) use ($handler, $isSse, $master, $keepAlive): void {
            $started = false;
            $activeFd = $req->fd;
            $this->activeHttpFds[$activeFd] = true;

            try {
                $psr = $this->requestConverter->toServerRequest($req);

                if ($isSse && str_contains($psr->getHeaderLine('accept'), 'text/event-stream')) {
                    $sseCid = \Swoole\Coroutine::getCid();
                    $this->sseCoroutineIds[$sseCid] = true;

                    try {
                        $headersSet = false;
                        $handled = $handler->handleSse(
                            $psr,
                            static function (string $data) use ($resp, &$headersSet, &$started): bool {
                                $started = true;

                                if (!$headersSet) {
                                    $resp->header('Content-Type', 'text/event-stream');
                                    $resp->header('Cache-Control', 'no-cache, no-transform');
                                    $resp->header('Connection', 'keep-alive');
                                    $resp->header('X-Accel-Buffering', 'no');
                                    $headersSet = true;
                                }

                                if (\Swoole\Coroutine::isCanceled()) {
                                    return false;
                                }

                                return $resp->write($data);
                            },
                            static function () use ($resp): void {
                                $resp->end();
                            },
                        );
                    } finally {
                        unset($this->sseCoroutineIds[$sseCid]);
                    }

                    if ($handled) {
                        if (!$resp->isWritable()) {
                            return;
                        }
                        $resp->end();

                        return;
                    }

                    if ($started) {
                        if ($resp->isWritable()) {
                            $resp->close();
                        }

                        return;
                    }
                }

                $psrResponse = $handler->handle($psr);
                $this->responseConverter->toSwoole($psrResponse, $resp, $psr->getMethod() === 'HEAD', $started);

                if (!$keepAlive && $activeFd > 0) {
                    $master->close($activeFd);
                }
            } catch (\Throwable $e) {
                if ($started) {
                    if ($resp->isWritable()) {
                        $resp->close();
                    }
                } else {
                    $resp->status(500);
                    $resp->end($e->getMessage());

                    if (!$keepAlive && $activeFd > 0) {
                        $master->close($activeFd);
                    }
                }
            } finally {
                unset($this->activeHttpFds[$activeFd]);
            }
        });

    }

    /**
     * Unpin the exiting worker so it drains promptly instead of hitting the
     * max_wait_time force-kill (ERRNO 9101): cancel in-flight SSE streams so
     * each unwinds, then — BASE mode, where workers own their sockets — close
     * every idle keep-alive connection (browsers hold several open; each pins
     * the reactor). Connections with an active request, accepted WS upgrades,
     * and live websocket_status fds are left untouched. Wired through the
     * lifecycle registry's workerExit multiplexer (Server subscribes its
     * transports) — NOT a raw $master->on('workerExit'), which the registry's
     * own registration would silently replace.
     */
    public function onWorkerExit(Server $server, int $workerId): void
    {
        if ($this->sseCoroutineIds !== []) {
            $cids = array_keys($this->sseCoroutineIds);

            \Swoole\Coroutine::create(static function () use ($cids): void {
                foreach ($cids as $cid) {
                    \Swoole\Coroutine::cancel($cid);
                }
            });
        }

        \Swoole\Timer::clearAll();

        if ($server->config()->mode !== SWOOLE_BASE) {
            return;
        }

        $master = $server->getMaster();

        foreach (array_keys($this->openFds) as $fd) {
            if (isset($this->activeHttpFds[$fd]) || isset($this->wsAcceptedFds[$fd])) {
                continue;
            }

            $info = $master->getClientInfo($fd);

            if (is_array($info) && ($info['websocket_status'] ?? 0) > 0) {
                continue;
            }

            $master->close($fd);
            unset($this->openFds[$fd]);
        }
    }
}
