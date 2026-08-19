# phpdot/server

Swoole-native application server for PHP 8.5. One process owner, attachable transports, and a
PSR-15 handler in front — HTTP, WebSocket, SSE, and raw TCP served from a single Swoole master.
It builds its PSR-7 messages with `phpdot/http`, so there is no third-party PSR-7 implementation
under the hood.

Beyond request handling it owns the whole runtime: worker and task-worker pools, the full Swoole
lifecycle as typed listener interfaces and attribute-discovered `#[ServerListener]` classes, a
server-wide connection registry (WebSocket push, TCP broadcast), coroutine-safe timers, live
statistics, graceful signal handling with drain diagnostics, an orphan-reaping watchdog, and a
supervising file watcher for hot reload in development. An operational CLI ships in the box:
start/stop/restart/reload/status plus cluster visibility over an optional Redis registry,
with a unix control socket for CLI introspection — operational endpoints are the application's.

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
  - [Feature overview](#feature-overview)
  - [Quick Start](#quick-start)
  - [The CLI](#the-cli)
  - [Protocols](#protocols)
  - [Transports](#transports)
  - [Connection operations](#connection-operations)
  - [Lifecycle events](#lifecycle-events)
  - [Server listeners](#server-listeners)
  - [Task workers](#task-workers)
  - [Timers](#timers)
  - [Server control](#server-control)
  - [Statistics](#statistics)
  - [Control socket and cluster](#control-socket-and-cluster)
  - [User processes and hot reload](#user-processes-and-hot-reload)
  - [Configuration](#configuration)
  - [Operational behaviour](#operational-behaviour)
- [Architecture](#architecture)
- [Testing](#testing)
- [License](#license)

## Requirements

| Requirement | Constraint |
|---|---|
| PHP | `>= 8.5` |
| ext-swoole | `>= 6.2` |
| `composer-runtime-api` | `^2.2` |
| `phpdot/console` | `^0.2` |
| `phpdot/contracts` | `^0.2` |
| `phpdot/http` | `^0.2` |
| `psr/container` | `^2.0` |
| `psr/http-factory` | `^1.0` |
| `psr/http-message` | `^2.0` |
| `psr/http-server-handler` | `^1.0` |
| `symfony/console` | `^8.0` |

## Installation

```bash
composer require phpdot/server
```

## Usage

### Feature overview

- **One master, many protocols** — HTTP, WebSocket, and SSE share the primary port; raw TCP
  attaches on its own port. All driven by one PSR-15 handler aggregate.
- **Self-contained PSR-7** — requests and responses are built with `phpdot/http`; no separate
  PSR-7 implementation required.
- **Attachable transports** — `HttpServer` (primary) and `TcpServer` implement a small
  `Transport` contract; the `Server` owns the process and wires them onto the Swoole master.
- **Typed lifecycle** — eleven `On*Interface` hooks (start, shutdown, reload, worker and manager
  events) fanned out from a single registry.
- **Connection registry** — a server-wide surface for WebSocket push/ping/disconnect and TCP
  broadcast, exposed to real-time layers through `ConnectionSenderInterface`.
- **Task workers** — offload blocking work to a task pool, with callback or coroutine-await
  completion.
- **Timers** — coroutine-safe recurring and one-shot timers.
- **Statistics** — master/manager/worker PIDs, worker status, and full Swoole stats, plus a
  unix control socket beside the pid file for CLI introspection (never an HTTP endpoint).
- **Operational CLI** — `server:start` (with `-d` and `--watch`), `stop`, `restart`, `reload`,
  `status`, and `cluster:status`, assembled by a config-driven `ServerFactory`.
- **Server listeners** — `#[ServerListener]` classes discovered by a subprocess scan (the
  pre-fork master never loads app classes, keeping them hot-reloadable), DI-constructed
  post-fork per worker, routed by their `__invoke` event parameter type.
- **Cluster visibility** — a Redis-backed node registry (optional `phpdot/redis`) with
  heartbeats and TTL expiry.
- **Operational safety** — fail-fast port checks, graceful SIGINT/SIGTERM teardown with real
  in-flight drain (BASE mode default), idle keep-alive close on worker exit, drain diagnostics,
  SSE cancellation on worker exit, and an orphan-reaping watchdog.
- **Hot reload** — the CLI's `--watch` supervises the server from outside: code edits reload
  workers, pre-fork edits (config, routes) drain and relaunch the whole process.

### Quick Start

```php
use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;

$factory = new ResponseFactory();

$server = new Server(new ServerConfig(workerNum: 4));
$server->attach(new HttpServer($factory, new HttpServerConfig(port: 8080)));
$server->serve($handler); // any PSR-15 RequestHandlerInterface — blocks on the event loop
```

### The CLI

In an application with `phpdot/console`, the command family is discovered automatically; the
`ServerFactory` reads the transport configs, attaches every `enabled` transport, subscribes
discovered listeners, and fails at creation time when nothing is enabled:

```
server:start [-d] [--watch]   start — daemonize with -d, develop with --watch ("serve" is an alias)
server:stop                   SIGTERM via the pid file, wait, escalate to SIGKILL (stopTimeout)
server:restart [-d]           graceful stop, then start again
server:reload [--task]        zero-downtime worker reload (SIGUSR1; --task reloads task workers)
server:status                 pid + uptime, enriched live over the control socket
server:cluster:status         every node in the Redis cluster registry, one table
```

`--watch` turns the command into a small supervisor: it launches the server as a child process
and watches the files itself — reload-classified edits SIGUSR1 the child (all workers re-fork),
restart-classified edits SIGTERM it gracefully and relaunch fresh. Restart classification is
empirical, not guessed: at boot the child records every file it loaded before forking (a
`preloaded.list` beside the pid file), and the supervisor restarts for changes to any of them —
a pre-fork file is frozen into worker memory by fork inheritance, so a reload could never apply
its edits. Everything else reloads. Signals only ever target the pid `proc_open` returned.
Library consumers can skip the CLI entirely and drive `ServerFactory::discover()` / `create()`
— or the raw `Server` — themselves.

### Protocols

The handler passed to `serve()` is the application's aggregate. It is always a PSR-15
`RequestHandlerInterface`; it opts into the other protocols by additionally implementing the
handler interfaces from `phpdot/contracts`:

| Protocol | Handler interface | Trigger |
|---|---|---|
| HTTP | `Psr\Http\Server\RequestHandlerInterface` | every request |
| WebSocket | `PHPdot\Contracts\Server\WebSocketHandlerInterface` | upgrade on the primary port |
| SSE | `PHPdot\Contracts\Server\SseHandlerInterface` | `Accept: text/event-stream` |
| TCP | `PHPdot\Contracts\Server\TcpHandlerInterface` | data on an attached `TcpServer` port |

A single object may implement all four — the same socket upgrades WebSocket connections, streams
SSE, and serves HTTP, while an attached `TcpServer` routes raw frames to the TCP hooks.

### Transports

`Server` owns the process and the Swoole master; transports attach to it. `HttpServer` is always
the primary transport (it owns the main port). `TcpServer` adds a raw-TCP port alongside it:

```php
use PHPdot\Server\Config\TcpServerConfig;
use PHPdot\Server\Tcp\TcpServer;

$server->attach(new HttpServer($factory, $httpConfig));
$server->attach(new TcpServer(new TcpServerConfig(port: 9501)));
$server->serve($handler); // handler also implements TcpHandlerInterface
```

TCP framing modes (`TcpFraming`): `Eof` (delimiter, default `"\n"`), `Length` (length-prefixed),
`None` (stream — primary-only). A non-primary TCP port requires framing; Swoole never fires
`receive` on an unframed added port.

### Connection operations

`ConnectionRegistry` is the server-wide connection surface, and implements the
`ConnectionSenderInterface` seam a real-time layer depends on to reach clients without naming a
concrete server:

```php
$registry->send($fd, $data);              // raw write to one connection
$registry->pushWs($fd, $frame);           // WebSocket push, established sockets only
$registry->pingWs($fd);                   // WebSocket PING control frame (heartbeats)
$registry->broadcastWs($frame);           // push to every open WebSocket
$registry->broadcast($data, $tcpPort);    // raw-TCP broadcast, never touches HTTP/WS sockets
$registry->disconnect($fd, 4401, 'bye');  // WebSocket close handshake; no-op on dead fds
$registry->close($fd);                    // close a connection
$registry->exists($fd);                   // liveness check
$registry->info($fd);                     // Swoole client info
$registry->list($startFd, $count);        // iterate connected fds
```

### Lifecycle events

Subscribe any object to the lifecycle registry; each `PHPdot\Server\Contract\On*Interface` it
implements is fanned out from a single composite per Swoole event:

```php
final class Bootstrap implements OnWorkerStartInterface, OnBeforeShutdownInterface
{
    public function onWorkerStart(\Swoole\Server $server, int $workerId): void { /* warm caches */ }
    public function onBeforeShutdown(\Swoole\Server $server): void { /* flush */ }
}

$server->events()->subscribe(new Bootstrap());
```

| Hook interface | Fires on |
|---|---|
| `OnStartInterface` | master start |
| `OnManagerStartInterface` / `OnManagerStopInterface` | manager process start / stop |
| `OnWorkerStartInterface` / `OnWorkerStopInterface` | worker start / stop |
| `OnWorkerExitInterface` | worker exit (drain) |
| `OnWorkerErrorInterface` | worker fatal error |
| `OnBeforeReloadInterface` / `OnAfterReloadInterface` | around a reload |
| `OnBeforeShutdownInterface` / `OnShutdownInterface` | around shutdown |

### Server listeners

The typed `On*Interface` contracts are the low-level SPI. Application code uses the high-level
form instead: mark a class `#[ServerListener]`, declare ONE `__invoke` whose parameter type is
the lifecycle event it wants, and drop it anywhere under a discovered path — no registration,
no subscription call:

```php
use PHPdot\Server\Attribute\ServerListener;
use PHPdot\Server\Event\WorkerStarted;

#[ServerListener]
final class WarmCache
{
    public function __construct(private readonly CacheWarmer $warmer) {}

    public function __invoke(WorkerStarted $event): void
    {
        $this->warmer->warm();   // DI-built, in-worker, post-fork
    }
}
```

Events: `ServerStarted`, `WorkerStarted`, `WorkerExiting`, `ServerShutdown`
(`PHPdot\Server\Event\`). The `ListenerBridge` fixes the class list pre-fork (the boot scan)
but constructs instances LAZILY through the container in whichever process first needs them —
post-fork in workers. A throwing listener is logged and isolated, never allowed to kill the
worker; a misdeclared one is skipped with a log line. Discovery paths are wired by the
application, exactly like console command discovery:

```php
$factory->discover([$vendorPhpdot, $appSourceDir]);   // then $factory->create()
```

### Task workers

Configure a task pool with `ServerConfig(taskWorkerNum: N)`, then offload blocking work off the
request workers:

```php
$server->onTask(fn (mixed $data) => heavyWork($data));  // runs in a task worker
$server->onFinish(fn (mixed $result) => log($result));  // back on the originating worker

// dispatch (from within a request):
$dispatcher->task($payload, onFinish: fn ($r) => handle($r));  // fire-and-callback
$results = $dispatcher->taskCo([$a, $b, $c], timeout: 0.5);    // dispatch + coroutine-await all
```

### Timers

Coroutine-safe timers over Swoole's timer wheel:

```php
$id = $timer->tick(1000, fn (int $id) => heartbeat());   // recurring, every 1000ms
$timer->after(5000, fn () => runOnce());                 // one-shot, after 5000ms
$timer->clear($id);                                      // cancel
```

### Server control

```php
$server->reload();                 // graceful worker reload (new code, no dropped connections)
$server->reload(onlyReloadTaskWorker: true);
$server->stop($workerId);          // stop one worker (it respawns)
$server->shutdown();               // stop the whole server
$server->ensurePortsAvailable();   // fail-fast port check before serve()
```

### Statistics

```php
$stats->all();            // full Swoole server stats (connections, requests, queued tasks, …)
$stats->masterPid();      // master / manager / worker identity
$stats->managerPid();
$stats->workerId();
$stats->workerPid($id);
$stats->workerStatus($id);
```

### Control socket and cluster

The server serves NO operational endpoints of its own — introspection is CLI territory, and
health checks are the application's to route. Instead, whenever a `pidFile` is configured, a
unix-domain **control socket** appears beside it (`server.pid` → `server.sock`): a private,
filesystem-permissioned line that `server:status` queries for the master's live `stats()`.
Nothing rides the network; a running pid whose socket cannot answer within a second is
reported as possibly wedged — the silence is itself a health signal. Unix socket paths cap at
~104 bytes, so keep `pidFile` reasonably shallow.

Health endpoints are the APPLICATION's responsibility (a route like any other — load
balancers speak HTTP, so the framework answers them; dot ships a `HealthController` on
`/healthz`). Reaching app code at all proves process, workers, routing, and kernel.

With `phpdot/redis` installed (a soft, optional coupling — the redis package knows nothing of
the server), the `Cluster` heartbeat publishes this node into a Redis registry every 5s with a
15s TTL: `node_id` (configured, or derived `hostname:port`), the master pid, uptime, and the
stats snapshot. `server:cluster:status` renders every node — `●` fresh, `○` stale with "last
seen Ns ago" — and dead nodes simply expire off the table. Registry writes are best-effort:
a Redis outage never harms a serving node.

### User processes and hot reload

`ProcessManager` (via `$server->processes()`) adds long-running user processes and drives the
file watcher for development hot reload:

```php
$server->processes()->add(fn (\Swoole\Process $p) => customLoop());

use PHPdot\Server\Watch\Watcher;
$server->processes()->watch(new Watcher(
    paths: [__DIR__ . '/src'],       // change here -> reload workers (new code)
    restart: [__DIR__ . '/config'],  // change here -> full server restart
    extensions: ['php'],
));
```

A change under `paths` reloads the workers; a change under `restart` restarts the whole server
(`WatchAction::Reload` / `Restart` / `Ignore` is the resolved per-change decision).

### Configuration

Four config DTOs, one per concern. In a dot application they hydrate from
`config/server/{master,http,tcp,watch}.php` via `#[Config]`; standalone consumers construct
them directly.

**`ServerConfig`** (`#[Config('server.master')]`) — process and pool tuning:

| Field | Default | Purpose |
|---|---|---|
| `workerNum` | `null` (CPU count) | request workers |
| `taskWorkerNum` | `0` | task workers |
| `maxRequest` | `100000` | requests before a worker recycles — auto-forced to `0` when the handler speaks SSE/WS or a TCP transport is attached (recycling kills open streams and raw connections); `override()` is the escape hatch |
| `mode` | `SWOOLE_BASE` | process model — BASE default: workers own their sockets, so stops DRAIN in-flight requests (PROCESS drops them) |
| `maxWaitTime` | `3` | drain seconds on reload/shutdown |
| `stopTimeout` | `15` | seconds `server:stop` waits before escalating to SIGKILL |
| `nodeId` | `''` | cluster identity; empty derives `hostname:httpPort` |
| `orphanWatchdog` | `true` | reap the tree if the master dies ungracefully |
| `hookFlags` | `SWOOLE_HOOK_ALL` | coroutine runtime hooks |
| `daemonize`, `pidFile`, `logFile`, `logLevel` | — | daemon / logging |
| `tcpNodelay`, `tcpKeepalive`, `backlog`, `bufferOutputSize`, `socketBufferSize`, `packageMaxLength` | — | socket tuning |
| `rawSettings` | `[]` | any Swoole setting without a typed field |

**`HttpServerConfig`** (`#[Config('server.http')]`) — `enabled`, `host`, `port`, `sockType`,
`serverSoftware`, `keepAlive` (disable in development so idle sockets never pin exiting BASE
workers), `http2`, `httpCompression` (+ level and min length), the
`httpParsePost` / `httpParseCookie` / `httpParseFiles` parsing toggles, `uploadTmpDir`,
`staticHandler` (+ `documentRoot`, `staticHandlerLocations`), and the `ssl*` fields
(cert/key/CA, peer verification, protocols, ciphers). The server serves no operational
endpoints — health routes are the application's (see below).

**`TcpServerConfig`** (`#[Config('server.tcp')]`) — `enabled` (off by default), `host`, `port`,
`sockType`, `framing`, and the framing parameters (`packageEof`, `packageLengthType`,
`lengthOffset`, `bodyOffset`, `packageMaxLength`).

**`WatchConfig`** (`#[Config('server.watch')]`) — what `--watch` watches: `paths` (directories
scanned recursively, listed files watched as-is), `extensions`, `excludes`, `restart` (path
segments that need a full restart because they load pre-fork), `depth`, `interval`, `debounce`.

### Operational behaviour

- **Fail-fast ports** — `serve()` refuses to start when a listen port is held, naming the exact
  host:port.
- **Graceful signals** — Ctrl+C (SIGINT) and SIGTERM tear the whole tree down cleanly, in PROCESS
  and BASE modes. BASE (the default) additionally drains in-flight requests to completion.
- **Idle keep-alive close on worker exit** — exiting BASE workers close their idle keep-alive
  sockets (sparing active requests, WS upgrades, and live streams), so reloads and shutdowns are
  never pinned to the max_wait_time force-kill by a browser's parked connections.
- **Orphan watchdog** (default on) — if the master dies without teardown, a watchdog process reaps
  the manager and workers instead of leaving an orphaned tree. macOS has no parent-death signal, so
  this is what prevents orphaned trees from poisoning the next boot.
- **Drain diagnostics** — a worker still pinned one second into shutdown logs exactly which
  coroutines and timers hold it, instead of a bare ERRNO 9101 force-kill.
- **SSE cancellation** — in-flight event streams are cancelled on worker exit so recycles never
  hang on a streaming loop.

## Architecture

```mermaid
graph TD
    CLI["CLI<br/><br/>start / stop / restart / reload /<br/>status / cluster:status"]
    FACTORY["ServerFactory<br/><br/>enabled transports + listener scan<br/>→ a ready Server"]
    APP["Your handler<br/><br/>PSR-15 / WebSocket / SSE / TCP"]
    SERVER["Server<br/><br/>owns the Swoole master:<br/>mode, hooks, worker lifecycle"]

    subgraph Transports
        direction TB
        HTTP["HttpServer<br/><br/>primary: HTTP + WS upgrades + SSE"]
        TCP["TcpServer<br/><br/>added port: length / EOF framing"]
    end

    subgraph Runtime["per worker"]
        direction TB
        EVENTS["LifecycleEventRegistry<br/><br/>start / worker / reload hooks,<br/>graceful SIGINT shutdown"]
        BRIDGE["ListenerBridge<br/><br/>#[ServerListener] classes,<br/>lazy post-fork construction"]
        TASK["TaskDispatcher<br/><br/>task workers"]
        TIMER["Timer<br/><br/>interval callbacks"]
        CONN["ConnectionRegistry<br/><br/>fd tracking + stats"]
        WATCH["OrphanWatchdog + Cluster Heartbeat<br/><br/>reap on tree death, Redis registry"]
    end

    CLI --> FACTORY
    FACTORY --> SERVER
    APP --> SERVER
    SERVER --> Transports
    SERVER --> Runtime
```

## Testing

The package is standalone-testable (requires ext-swoole):

```bash
composer install
composer test        # PHPUnit — integration tests boot real servers
composer analyse     # PHPStan, level max + strict rules
composer cs-check    # PHP-CS-Fixer
composer check       # All three
```

Integration tests boot real servers and assert raw bytes on the wire — HTTP parity, WebSocket
frames, SSE streams, TCP framing, signal handling, and orphan reaping. They build all PSR-7 inputs
and responses with `phpdot/http`; no external PSR-7 library is used.

## License

MIT.

**This repository is a read-only mirror**, generated by CI from
[phpdot/monorepo](https://github.com/phpdot/monorepo). [Pull requests](https://github.com/phpdot/monorepo/pulls)
and [issues](https://github.com/phpdot/monorepo/issues) belong in the monorepo.
