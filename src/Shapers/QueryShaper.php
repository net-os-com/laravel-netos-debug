<?php

declare(strict_types=1);

namespace NetOs\Debug\Shapers;

/**
 * Turns debugbar's `queries.statements` into the query rows the Requests view
 * renders, including the backtrace frames shown under each query.
 */
class QueryShaper
{
    /**
     * The statements that become rows in the view, in the order they appear
     * there. Transactions, and the synthetic `info` rows debugbar injects when
     * its soft/hard limits trip, are not queries the view can render.
     *
     * Public because the query planner has to walk the same list in the same
     * order to attach plans to the right rows.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public static function statements(array $data): array
    {
        $queries = $data['queries'] ?? [];
        $statements = is_array($queries) ? ($queries['statements'] ?? []) : [];

        return array_values(array_filter(
            is_array($statements) ? $statements : [],
            fn ($statement): bool => is_array($statement) && ($statement['type'] ?? null) === 'query',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function shape(array $data, float $startedAt): array
    {
        $queries = [];

        foreach (self::statements($data) as $statement) {
            $queries[] = [
                'sql' => (string) ($statement['sql'] ?? ''),
                'bindings' => $this->bindings($statement['params'] ?? []),
                'durationMs' => round((float) ($statement['duration'] ?? 0) * 1_000, 3),
                'offsetMs' => round(((float) ($statement['start'] ?? $startedAt) - $startedAt) * 1_000, 3),
                'source' => (string) ($statement['filename'] ?? ''),
                'connection' => (string) ($statement['connection'] ?? ''),
                // Debugbar 4 dropped hints entirely, and its `explain` key is a
                // handle for an on-demand AJAX call rather than the rows. The
                // view derives its own N+1 and duplicate warnings; plans are
                // attached separately by the query planner.
                'explain' => null,
                'hint' => null,
                'trace' => $this->trace($statement['backtrace'] ?? []),
            ];
        }

        return $queries;
    }

    /**
     * Bindings are substituted straight into the SQL by the view, so strings
     * arrive already quoted and escaped. Without that, "Copy as SQL" hands back
     * something that does not run.
     *
     * @param  array<int, mixed>  $params
     * @return list<string>
     */
    private function bindings(array $params): array
    {
        return array_values(array_map(function ($param): string {
            if (is_bool($param)) {
                return $param ? 'true' : 'false';
            }

            if ($param === null) {
                return 'null';
            }

            if (is_int($param) || is_float($param)) {
                return (string) $param;
            }

            $value = is_scalar($param) ? (string) $param : (json_encode($param) ?: '');

            return "'".str_replace("'", "''", $value)."'";
        }, $params));
    }

    /**
     * Debugbar's frames carry no class or method, only a normalized path (or a
     * view name, a middleware alias, or "Route binding"), so that name is the
     * most honest thing to show as the callable.
     *
     * @param  array<int, mixed>  $backtrace
     * @return list<array<string, mixed>>
     */
    private function trace(array $backtrace): array
    {
        $frames = [];

        foreach ($backtrace as $frame) {
            $frame = (array) $frame;
            $name = $frame['name'] ?? null;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $frames[] = [
                'callable' => $name,
                'file' => mb_trim($name.':'.($frame['line'] ?? '')),
                'application' => ! str_starts_with($name, 'vendor/'),
            ];
        }

        return $frames;
    }
}
