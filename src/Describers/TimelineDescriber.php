<?php

declare(strict_types=1);

namespace NetOs\Debug\Describers;

/**
 * Debugbar's timeline measures. `relative_start` and `duration` are seconds
 * from the request's start, which is already what a timeline wants; only the
 * unit changes.
 *
 * Which collectors appear here is a config question, not a code one: a
 * collector contributes measures only when its own `timeline` option is on.
 */
class TimelineDescriber
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function describe(array $data): array
    {
        $time = is_array($data['time'] ?? null) ? $data['time'] : [];
        $measures = is_array($time['measures'] ?? null) ? $time['measures'] : [];

        return array_values(array_map(function ($measure): array {
            $measure = (array) $measure;

            return [
                'label' => (string) ($measure['label'] ?? ''),
                'offsetMs' => round((float) ($measure['relative_start'] ?? 0) * 1_000, 3),
                'durationMs' => round((float) ($measure['duration'] ?? 0) * 1_000, 3),
                'collector' => (string) ($measure['collector'] ?? ''),
                'group' => $measure['group'] ?? null,
            ];
        }, $measures));
    }
}
