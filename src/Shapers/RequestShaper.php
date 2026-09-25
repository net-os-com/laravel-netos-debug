<?php

declare(strict_types=1);

namespace NetOs\Debug\Shapers;

use Illuminate\Http\Request;
use NetOs\Debug\Describers\CacheDescriber;
use NetOs\Debug\Describers\EventsDescriber;
use NetOs\Debug\Describers\RequestDescriber;
use NetOs\Debug\Describers\RouteDescriber;
use NetOs\Debug\Describers\TimelineDescriber;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reshapes one `Debugbar::getData()` dump into the request contract the NetOS
 * Debug Requests view consumes.
 *
 * Debugbar's own shape is collector-keyed and carries display strings next to
 * raw values; the view wants a flat request with numeric durations. Translating
 * here rather than in the desktop app keeps the debugbar version a detail of
 * this package. Each tab in the view has its own describer; this only assembles
 * them.
 */
class RequestShaper
{
    /** Collectors whose item count is not simply `count`. */
    private const array COUNT_KEYS = [
        'queries' => 'nb_statements',
        'views' => 'nb_templates',
    ];

    public function __construct(
        private readonly QueryShaper $queries,
        private readonly RouteDescriber $route,
        private readonly RequestDescriber $request,
        private readonly EventsDescriber $events,
        private readonly CacheDescriber $cache,
        private readonly TimelineDescriber $timeline,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function shape(array $data, Request $request, Response $response): array
    {
        $meta = $this->section($data, '__meta');
        $time = $this->section($data, 'time');
        $startedAt = (float) ($time['start'] ?? $meta['utime'] ?? microtime(true));

        return [
            'id' => (string) ($meta['id'] ?? ''),
            'method' => (string) ($meta['method'] ?? $request->getMethod()),
            'host' => $request->getSchemeAndHttpHost(),
            'uri' => (string) ($meta['uri'] ?? $request->getRequestUri()),
            // Debugbar's meta carries no response status, so it comes from the
            // response itself.
            'status' => $response->getStatusCode(),
            'startedAt' => (int) round($startedAt * 1_000),
            'durationMs' => round((float) ($time['duration'] ?? 0) * 1_000, 3),
            'memoryMb' => $this->memoryMb($data),
            'route' => $this->route->describe($request, $data),
            ...$this->request->describe($request, $response, $data),
            'events' => $this->events->describe($data),
            'cache' => $this->cache->describe($data),
            'queries' => $this->queries->shape($data, $startedAt),
            'timeline' => $this->timeline->describe($data),
            'collectorCounts' => $this->collectorCounts($data),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function section(array $data, string $key): array
    {
        $section = $data[$key] ?? [];

        return is_array($section) ? $section : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, int>
     */
    private function collectorCounts(array $data): array
    {
        $counts = [];

        foreach ($data as $collector => $collected) {
            if ($collector === '__meta' || ! is_array($collected)) {
                continue;
            }

            $count = $collected[self::COUNT_KEYS[$collector] ?? 'count'] ?? null;

            if (is_int($count)) {
                $counts[$collector] = $count;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function memoryMb(array $data): ?float
    {
        $bytes = $this->section($data, 'memory')['peak_usage'] ?? null;

        return is_numeric($bytes) ? round((float) $bytes / 1_024 / 1_024, 2) : null;
    }
}
