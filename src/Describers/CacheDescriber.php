<?php

declare(strict_types=1);

namespace NetOs\Debug\Describers;

/**
 * The cache operations a request performed.
 *
 * Debugbar records these as timeline measures whose label is the operation and
 * the key joined by a tab, with the store and any tags in the parameters. This
 * unpacks that into rows the Cache tab can render.
 */
class CacheDescriber
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function describe(array $data): array
    {
        $cache = is_array($data['cache'] ?? null) ? $data['cache'] : [];
        $measures = is_array($cache['measures'] ?? null) ? $cache['measures'] : [];
        $operations = [];

        foreach ($measures as $measure) {
            $measure = (array) $measure;
            $label = (string) ($measure['label'] ?? '');

            if ($label === '') {
                continue;
            }

            [$operation, $key] = array_pad(explode("\t", $label, 2), 2, '');
            $parameters = (array) ($measure['params'] ?? []);
            $tags = array_filter((array) ($parameters['tags'] ?? []), 'is_string');

            $operations[] = [
                'operation' => $operation,
                'key' => (string) ($parameters['key'] ?? $key),
                'store' => (string) ($parameters['storeName'] ?? ''),
                'tags' => array_values($tags),
                'offsetMs' => round((float) ($measure['relative_start'] ?? 0) * 1_000, 3),
                'durationMs' => round((float) ($measure['duration'] ?? 0) * 1_000, 3),
            ];
        }

        return $operations;
    }
}
