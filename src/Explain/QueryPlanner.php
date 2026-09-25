<?php

declare(strict_types=1);

namespace NetOs\Debug\Explain;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use NetOs\Debug\Shapers\QueryShaper;

/**
 * Attaches a query plan to each select in an already-shaped request.
 *
 * Debugbar 4 does not put EXPLAIN output in its payload — `statements[].explain`
 * is a handle for an on-demand AJAX call against a stored request, which is no
 * use to a desktop app reading a pushed payload. So the plans are run here, in
 * the middleware's terminate, where nothing is waiting on them.
 */
class QueryPlanner
{
    /**
     * The columns the Requests view renders, in its order. MySQL returns twelve
     * and the design shows eight, so the row is projected rather than passed
     * through; anything the driver does not return becomes an empty cell.
     */
    private const array COLUMNS = [
        'id',
        'select_type',
        'table',
        'type',
        'key',
        'rows',
        'filtered',
        'Extra',
    ];

    /** Throwaway connection, so nothing touches the ones the app is using. */
    private const string CONNECTION = 'netos-debug-explain';

    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $database,
    ) {}

    /**
     * @param  array<string, mixed>  $shaped
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attach(array $shaped, array $data): array
    {
        if (! $this->config->get('netos-debug.explain_queries', false)) {
            return $shaped;
        }

        $budget = (int) $this->config->get('netos-debug.max_explained_queries', 25);

        try {
            foreach (QueryShaper::statements($data) as $index => $statement) {
                if ($budget <= 0) {
                    break;
                }

                if (! isset($shaped['queries'][$index]) || ! $this->isSelect($statement)) {
                    continue;
                }

                $budget -= 1;
                $shaped['queries'][$index]['explain'] = $this->explain($statement);
            }
        } finally {
            $this->database->purge(self::CONNECTION);
        }

        return $shaped;
    }

    /**
     * @param  array<string, mixed>  $statement
     * @return list<list<string>>|null
     */
    private function explain(array $statement): ?array
    {
        $database = (string) ($statement['connection'] ?? '');

        if ($database === '') {
            return null;
        }

        // A plan is a nicety; a query the throwaway connection cannot reach
        // simply arrives without one.
        $rows = rescue(
            fn (): array => $this->connection($database)
                ->select('EXPLAIN '.$statement['sql'], (array) ($statement['params'] ?? [])),
            null,
            report: false,
        );

        return $rows === null
            ? null
            : array_values(array_map(fn ($row): array => $this->project((array) $row), $rows));
    }

    /**
     * Debugbar reduces a query's connection to the name of the database it ran
     * against, which under tenancy is not the database that connection points
     * at by the time terminate runs. So the plan is run on a connection pinned
     * to that database instead of on whatever the app left current.
     */
    private function connection(string $database): ConnectionInterface
    {
        $current = $this->config->get('database.connections.'.self::CONNECTION.'.database');

        if ($current !== $database) {
            /** @var array<string, mixed> $default */
            $default = $this->config->get('database.connections.'.$this->config->get('database.default'));

            $this->config->set(
                'database.connections.'.self::CONNECTION,
                ['database' => $database] + $default,
            );

            $this->database->purge(self::CONNECTION);
        }

        return $this->database->connection(self::CONNECTION);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function project(array $row): array
    {
        return array_map(
            fn (string $column): string => ($row[$column] ?? null) === null ? '' : (string) $row[$column],
            self::COLUMNS,
        );
    }

    /**
     * @param  array<string, mixed>  $statement
     */
    private function isSelect(array $statement): bool
    {
        return Str::startsWith(Str::lower(mb_ltrim((string) ($statement['sql'] ?? ''))), 'select');
    }
}
