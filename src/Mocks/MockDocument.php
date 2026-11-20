<?php

declare(strict_types=1);

namespace NetOs\Debug\Mocks;

/**
 * Converts between the YAML a person edits and the JSON shape the desktop app
 * reads: snake_case and omitted-when-empty on disk, camelCase and always
 * complete on the wire.
 */
final class MockDocument
{
    /**
     * @param  array<string, mixed>  $yaml
     * @return array<string, mixed>
     */
    public static function toContract(string $name, array $yaml): array
    {
        return [
            'name' => $name,
            'title' => is_string($yaml['name'] ?? null) && $yaml['name'] !== '' ? $yaml['name'] : $name,
            'endpoints' => array_map(self::endpoint(...), self::rows($yaml['endpoints'] ?? [])),
            'webhooks' => array_map(self::webhook(...), self::rows($yaml['webhooks'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array<string, mixed>
     */
    public static function toYaml(array $contract): array
    {
        return array_filter([
            'name' => $contract['title'] ?? $contract['name'] ?? '',
            'endpoints' => array_map(self::endpointYaml(...), self::rows($contract['endpoints'] ?? [])),
            'webhooks' => array_map(self::webhookYaml(...), self::rows($contract['webhooks'] ?? [])),
        ], fn (mixed $value): bool => $value !== []);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function endpoint(array $row): array
    {
        return [
            'id' => $row['id'] ?? '',
            'name' => $row['name'] ?? '',
            'method' => $row['method'] ?? '',
            'path' => $row['path'] ?? '',
            'query' => self::map($row['query'] ?? []),
            'responses' => array_map(self::response(...), self::rows($row['responses'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function response(array $row): array
    {
        return [
            'id' => $row['id'] ?? '',
            'name' => $row['name'] ?? '',
            'status' => $row['status'] ?? 200,
            'headers' => self::map($row['headers'] ?? []),
            'delayMs' => $row['delay_ms'] ?? 0,
            'body' => $row['body'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function webhook(array $row): array
    {
        $signature = is_array($row['signature'] ?? null) ? $row['signature'] : [];

        return [
            'id' => $row['id'] ?? '',
            'name' => $row['name'] ?? '',
            'method' => $row['method'] ?? 'POST',
            'path' => $row['path'] ?? '',
            'headers' => self::map($row['headers'] ?? []),
            'body' => $row['body'] ?? '',
            'signature' => [
                'kind' => $signature['kind'] ?? 'none',
                'header' => $signature['header'] ?? '',
                'prefix' => $signature['prefix'] ?? '',
                'secretConfig' => $signature['secret_config'] ?? '',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function endpointYaml(array $row): array
    {
        return self::compact([
            'id' => $row['id'] ?? '',
            'name' => $row['name'] ?? '',
            'method' => $row['method'] ?? '',
            'path' => $row['path'] ?? '',
            'query' => self::plain($row['query'] ?? []),
            'responses' => array_map(self::responseYaml(...), self::rows($row['responses'] ?? [])),
        ], ['id', 'name', 'method', 'path', 'responses']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function responseYaml(array $row): array
    {
        return self::compact([
            'id' => $row['id'] ?? '',
            'name' => $row['name'] ?? '',
            'status' => $row['status'] ?? 200,
            'headers' => self::plain($row['headers'] ?? []),
            'delay_ms' => $row['delayMs'] ?? 0,
            'body' => $row['body'] ?? '',
        ], ['id', 'name', 'status']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function webhookYaml(array $row): array
    {
        $signature = is_array($row['signature'] ?? null) ? $row['signature'] : [];
        $kind = $signature['kind'] ?? 'none';

        return self::compact([
            'id' => $row['id'] ?? '',
            'name' => $row['name'] ?? '',
            'method' => $row['method'] ?? 'POST',
            'path' => $row['path'] ?? '',
            'headers' => self::plain($row['headers'] ?? []),
            'signature' => $kind === 'none' ? [] : [
                'kind' => $kind,
                'header' => $signature['header'] ?? '',
                'prefix' => $signature['prefix'] ?? '',
                'secret_config' => $signature['secretConfig'] ?? '',
            ],
            'body' => $row['body'] ?? '',
        ], ['id', 'name', 'method', 'path']);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function compact(array $row, array $required): array
    {
        return array_filter(
            $row,
            fn (mixed $value, string $key): bool => in_array($key, $required, true) || ! in_array($value, [[], '', 0], true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /**
     * An empty map has to leave as `{}`, which PHP can only say with an object.
     *
     * @return array<string, mixed>|\stdClass
     */
    private static function map(mixed $value): array|\stdClass
    {
        return is_array($value) && $value !== [] ? $value : new \stdClass;
    }

    /** @return array<string, mixed> */
    private static function plain(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
