<?php

declare(strict_types=1);

namespace NetOs\Debug\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NetOs\Debug\Collectors\RequestCollector;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Decides whether a finished request is shipped to NetOS Debug, and leaves the
 * shipping itself to the collector.
 *
 * The work happens in `terminate()`, which fires after the response has been
 * sent, so neither shaping nor an unreachable desktop app adds latency.
 *
 * Deliberately not wrapped in `defer()`: Laravel's InvokeDeferredCallbacks is
 * prepended to the middleware stack, so it terminates before this middleware
 * does and a callback queued here is simply dropped. `terminate()` is already
 * the post-response seam.
 */
class SendRequestToNetosDebug
{
    /** Set once a failure has been reported, so the log keeps one line. */
    private static bool $reported = false;

    public function __construct(private readonly Repository $config) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->isExcluded($request)) {
            return;
        }

        // A debug tool must never be able to fail a request — but it must not
        // drop one in silence either. Swallowing everything once hid a cast
        // that threw on authenticated requests, so signed-in traffic quietly
        // stopped arriving while preflights kept coming through.
        try {
            app(RequestCollector::class)->collect($request, $response);
        } catch (Throwable $exception) {
            $this->reportOnce($exception);
        }
    }

    /**
     * One line per process. Repeating it for every request would bury the log
     * of the application being debugged, which is the opposite of helpful.
     */
    private function reportOnce(Throwable $exception): void
    {
        if (self::$reported) {
            return;
        }

        self::$reported = true;

        Log::warning('[netos-debug] collecting this request failed, so it was not sent', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'at' => $exception->getFile().':'.$exception->getLine(),
        ]);
    }

    private function isExcluded(Request $request): bool
    {
        /** @var list<string> $except */
        $except = $this->config->get('netos-debug.except', []);

        return $except !== [] && $request->is(...$except);
    }
}
