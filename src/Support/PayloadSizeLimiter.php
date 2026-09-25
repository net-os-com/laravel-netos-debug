<?php

declare(strict_types=1);

namespace NetOs\Debug\Support;

/**
 * Keeps a shaped request under the configured byte budget, so a pathological
 * request cannot flood the desktop app's ring buffer.
 */
class PayloadSizeLimiter
{
    public function __construct(private readonly int $maxBytes) {}

    /**
     * Backtraces dominate the payload, so they go first; only if dropping them
     * is still not enough does the query list itself get cut, because a request
     * with half its queries still says more than no request at all.
     *
     * @param  array<string, mixed>  $shaped
     * @return array<string, mixed>
     */
    public function limit(array $shaped): array
    {
        if ($this->fits($shaped)) {
            return $shaped;
        }

        $queries = is_array($shaped['queries'] ?? null) ? $shaped['queries'] : [];

        $shaped['queries'] = array_map(
            fn (array $query): array => [...$query, 'trace' => []],
            $queries,
        );

        while (! $this->fits($shaped) && $shaped['queries'] !== []) {
            array_splice($shaped['queries'], (int) ceil(count($shaped['queries']) / 2));
        }

        return $shaped;
    }

    /**
     * @param  array<string, mixed>  $shaped
     */
    private function fits(array $shaped): bool
    {
        return mb_strlen((string) json_encode($shaped)) <= $this->maxBytes;
    }
}
