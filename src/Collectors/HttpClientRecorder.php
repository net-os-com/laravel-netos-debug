<?php

declare(strict_types=1);

namespace NetOs\Debug\Collectors;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NetOs\Debug\Payloads\OutgoingRequestPayload;
use NetOs\Debug\Shapers\OutgoingRequestShaper;
use Spatie\LaravelRay\Ray;
use Throwable;

/**
 * Watches the calls this application makes, and ships each one as it finishes.
 *
 * Laravel fires three events around an outgoing call but none of them says how
 * long it took, so the pair has to be matched up here. `RequestSending` and
 * `ResponseReceived` carry the very same Request object — the pending request's
 * own — so they match on object identity. A connection failure does not: it is
 * dispatched with a freshly built Request, as are the responses of a pool, so
 * those fall back to the most recent call waiting on that verb and URL.
 *
 * Shipped per call rather than attached to the request that made them, because
 * plenty of them are made where there is no request: a queued job refreshing a
 * token, the scheduler talking to an upstream, a command somebody is running.
 */
class HttpClientRecorder
{
    /**
     * Calls that have been sent and not yet answered, by object id.
     *
     * @var array<int, array{at: float, key: string, trace: array<int, array{function: string, file: string, line: int, vendor: bool}>}>
     */
    private array $inFlight = [];

    /** Set once a failure has been reported, so the log keeps one line. */
    private static bool $reported = false;

    /**
     * How deep the call site is recorded. Deep enough to pass the framework and
     * whatever SDK made the call, shallow enough that the backtrace itself is
     * not the cost of watching.
     */
    private const int TRACE_FRAMES = 40;

    public function __construct(
        private readonly Repository $config,
        private readonly OutgoingRequestShaper $shaper,
        private readonly Ray $ray,
    ) {}

    public function sending(RequestSending $event): void
    {
        if (! $this->wanted($event->request->url())) {
            return;
        }

        $this->inFlight[spl_object_id($event->request)] = [
            'at' => microtime(true),
            'key' => $event->request->method().' '.$event->request->url(),
            // Taken here, where the application's own frames are still on the
            // stack: by the time the response comes back, the call site is gone
            // and all that is left is the event dispatcher.
            'trace' => $this->trace(),
        ];
    }

    public function received(ResponseReceived $event): void
    {
        $call = $this->take($event->request);

        if ($call === null) {
            return;
        }

        $this->send(fn (): array => $this->shaper->shape(
            $event->request,
            $event->response,
            null,
            $call['at'],
            microtime(true),
            $call['trace'],
        ));
    }

    public function failed(ConnectionFailed $event): void
    {
        $call = $this->take($event->request);

        if ($call === null) {
            return;
        }

        $this->send(fn (): array => $this->shaper->shape(
            $event->request,
            null,
            $event->exception->getMessage(),
            $call['at'],
            microtime(true),
            $call['trace'],
        ));
    }

    /**
     * What was recorded when the call was sent, and forget it. Null when it was
     * never recorded, which is how an excluded host stays excluded on the way
     * back.
     *
     * @return array{at: float, key: string, trace: array<int, array{function: string, file: string, line: int, vendor: bool}>}|null
     */
    private function take(object $request): ?array
    {
        $id = spl_object_id($request);

        if (isset($this->inFlight[$id])) {
            $call = $this->inFlight[$id];
            unset($this->inFlight[$id]);

            return $call;
        }

        // A failure, or a pooled response: same call, different object. The
        // newest match wins, which is the right guess when the same endpoint is
        // called twice in a row and only one of them has come back.
        $key = $request->method().' '.$request->url();

        foreach (array_reverse(array_keys($this->inFlight)) as $candidate) {
            if ($this->inFlight[$candidate]['key'] === $key) {
                $call = $this->inFlight[$candidate];
                unset($this->inFlight[$candidate]);

                return $call;
            }
        }

        return null;
    }

    /**
     * Where the call was made from.
     *
     * Vendor frames are kept rather than dropped — a call through an SDK is
     * nothing but vendor frames until you reach the one line of ours that asked
     * for it — but they are marked, so the view can fold them away and show the
     * three frames somebody actually wants.
     *
     * @return array<int, array{function: string, file: string, line: int, vendor: bool}>
     */
    private function trace(): array
    {
        $frames = [];
        // This collector's own frames sit on top of every trace it takes, and
        // pointing at the watcher instead of at the call is worse than useless.
        $ours = dirname(__DIR__).DIRECTORY_SEPARATOR;
        $namespace = 'NetOs\\Debug\\';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_FRAMES) as $frame) {
            $file = (string) ($frame['file'] ?? '');

            // A frame's file is where the call was made from, but its class is
            // what was called — so this collector shows up twice, once by path
            // and once by name, and both have to go.
            if ($file === '' || str_starts_with($file, $ours)) {
                continue;
            }

            if (str_starts_with((string) ($frame['class'] ?? ''), $namespace)) {
                continue;
            }

            $name = isset($frame['class'])
                ? $frame['class'].($frame['type'] ?? '::').$frame['function']
                : $frame['function'];

            $frames[] = [
                'function' => $name.'()',
                'file' => $file,
                'line' => (int) ($frame['line'] ?? 0),
                'vendor' => str_contains($file, '/vendor/'),
            ];
        }

        return $frames;
    }

    /**
     * @param  callable(): array<string, mixed>  $shape
     */
    private function send(callable $shape): void
    {
        // A debug tool must never be able to fail the call it is watching, and
        // this one runs inside the application's own request path rather than
        // after it, where the collected-request middleware sits.
        try {
            $this->ray->sendRequest([new OutgoingRequestPayload($shape())]);
        } catch (Throwable $exception) {
            $this->reportOnce($exception);
        }
    }

    private function wanted(string $url): bool
    {
        /** @var list<string> $except */
        $except = $this->config->get('netos-debug.http_client.except', []);

        foreach ($except as $pattern) {
            if (Str::is($pattern, $url)) {
                return false;
            }
        }

        return true;
    }

    private function reportOnce(Throwable $exception): void
    {
        if (self::$reported) {
            return;
        }

        self::$reported = true;

        Log::warning('[netos-debug] an outgoing call could not be shipped', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'at' => $exception->getFile().':'.$exception->getLine(),
        ]);
    }
}
