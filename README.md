# NetOS Debug — Laravel integration

Ships the full Laravel Debugbar payload for every finished request to the
[NetOS Debug](https://github.com/net-os-com/debug-ray) desktop app, which
renders it as a debugbar clone in its Requests view.

Internal Net OS package. Not published on Packagist.

## Installation

Add the repository, then require it as a dev dependency:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/net-os-com/laravel-netos-debug" }
]
```

```bash
composer require --dev net-os/laravel-netos-debug
```

That is the whole setup. The package registers its own middleware and applies
the Debugbar options it needs; there is nothing to publish and nothing to wire
up by hand.

It ships requests only in `local` by default, and reads the NetOS Debug host and
port from your existing `ray.php` — including `remote_path` / `local_path`, so
paths from a Docker container stay clickable on the host.

## Configuration

Publish the config only if you want to change something:

```bash
php artisan vendor:publish --tag=netos-debug-config
```

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `null` | `null` defers to `environments`; an explicit value wins either way |
| `environments` | `['local']` | Where requests are shipped when `enabled` is null |
| `except` | debugbar, telescope, horizon, ignition | Paths never shipped, matched with `Request::is()` |
| `max_payload_bytes` | 512 KB | Larger payloads are trimmed before sending |
| `configure_debugbar` | `true` | Whether the package applies the Debugbar options below |

### Debugbar options this package applies

| Option | Set to | Why |
|---|---|---|
| `collectors.route` | `true` | Route name, action and middleware |
| `options.db.with_params` | `false` | The view wants raw SQL with `?` plus separate bindings |
| `options.db.backtrace` | `true` | Per-query backtrace |
| `options.db.timeline` | `true` | Queries on the shared timeline |

`with_params` is the one worth knowing about. Debugbar defaults to substituting
bindings into the SQL string; the Requests view expects placeholders alongside a
separate bindings list so it can toggle between them and rebuild a copyable
statement. Left on, that toggle silently does nothing.

These are applied while registering, not while booting: Debugbar reads them
during its own `boot()`, and the boot order of auto-discovered providers is not
guaranteed.

Set `configure_debugbar` to `false` to own these yourself.

## How it works

The middleware does its work in `terminate()`, after the response has been sent,
so neither shaping nor an unreachable desktop app adds latency. Debugbar has
normally already collected by then, on `RequestHandled`; `getData()` collects
lazily if it has not. Everything is wrapped in `rescue()` — a debug tool must
never be able to fail a request.

It is deliberately not wrapped in `defer()`. Laravel's
`InvokeDeferredCallbacks` is prepended to the middleware stack, so it terminates
*before* this middleware does, and a callback queued here would simply be
dropped.

## Known gaps

Three fields cannot be filled from Debugbar 4 and are sent as `null` or
degraded:

- **`explain`** — Debugbar 4 returns a handle for an on-demand AJAX call, not
  the rows, and only when its storage is open.
- **`hint`** — Debugbar 4 dropped hints. No loss: the desktop app derives its
  own duplicate and N+1 warnings.
- **`trace[].callable`** — Debugbar's frames carry no class or method, only a
  normalized path, a view name, a middleware alias or `"Route binding"`.

When Debugbar filters out every frame of a query through its own
`exclude_paths`, that query's `source` comes back empty.

## Development

```bash
composer analyse   # PHPStan level 6
composer format    # Pint
```

There is no test suite; verification is a real request against a running app
with NetOS Debug open.
