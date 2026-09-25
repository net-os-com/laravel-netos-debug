<?php

declare(strict_types=1);

namespace NetOs\Debug\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use NetOs\Debug\Collectors\RequestCollector;
use Symfony\Component\HttpFoundation\Response;

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

        // A debug tool must never be able to fail a request.
        rescue(fn () => app(RequestCollector::class)->collect($request, $response), report: false);
    }

    private function isExcluded(Request $request): bool
    {
        /** @var list<string> $except */
        $except = $this->config->get('netos-debug.except', []);

        return $except !== [] && $request->is(...$except);
    }
}
