<?php

declare(strict_types=1);

namespace NetOs\Debug\Collections;

/**
 * Turns saved requests into an OpenAPI document, and back.
 *
 * OpenAPI rather than a private format because these files are meant to be read
 * in a pull request and opened by something other than this app. The round trip
 * has to be lossless in the direction that matters: whatever the desktop app
 * saved, it gets back.
 */
final class OpenApiDocument
{
    private const string VERSION = '3.1.0';

    /** Where the parts of a request that OpenAPI has no field for are kept. */
    private const string EXTENSION = 'x-netos-debug';

    /**
     * @param  array<int, SavedRequest>  $requests
     * @return array<string, mixed>
     */
    public static function fromRequests(string $title, array $requests): array
    {
        $paths = [];

        foreach ($requests as $request) {
            $verb = mb_strtolower($request->method);

            // Two saved calls can share a path and differ only in their verb,
            // which is exactly how OpenAPI wants them stored.
            $paths[$request->path][$verb] = self::operation($request);
        }

        ksort($paths);

        return [
            'openapi' => self::VERSION,
            'info' => ['title' => $title, 'version' => '1.0.0'],
            'paths' => $paths,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<int, SavedRequest>
     */
    public static function toRequests(array $document): array
    {
        $requests = [];
        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

        foreach ($paths as $path => $operations) {
            if (! is_array($operations)) {
                continue;
            }

            foreach ($operations as $verb => $operation) {
                if (! is_array($operation) || ! is_string($verb)) {
                    continue;
                }

                $requests[] = self::request((string) $path, $verb, $operation);
            }
        }

        return $requests;
    }

    /**
     * @return array<string, mixed>
     */
    private static function operation(SavedRequest $request): array
    {
        $operation = [
            'operationId' => $request->operationId(),
            'summary' => $request->summary !== '' ? $request->summary : $request->name,
        ];

        $parameters = [];

        foreach ($request->pathParams as $parameter) {
            $parameters[] = self::parameter($parameter, 'path', true);
        }

        foreach ($request->query as $parameter) {
            $parameters[] = self::parameter($parameter, 'query', false);
        }

        foreach ($request->headers as $parameter) {
            $parameters[] = self::parameter($parameter, 'header', false);
        }

        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        if ($request->bodyMode === 'json' && $request->json !== '') {
            $decoded = json_decode($request->json, true);

            $operation['requestBody'] = [
                'content' => [
                    'application/json' => [
                        // A body that parses is stored as data, so a reviewer
                        // reads JSON rather than a string containing JSON.
                        'example' => $decoded ?? $request->json,
                    ],
                ],
            ];
        }

        // Everything OpenAPI has nowhere to put: which body editor was open,
        // and which way the request authenticates.
        $operation[self::EXTENSION] = [
            'name' => $request->name,
            'bodyMode' => $request->bodyMode,
            'auth' => $request->auth,
        ];

        return $operation;
    }

    /**
     * @param  array{key: string, value: string, enabled?: bool}  $pair
     * @return array<string, mixed>
     */
    private static function parameter(array $pair, string $in, bool $required): array
    {
        $parameter = [
            'name' => $pair['key'],
            'in' => $in,
            'schema' => ['type' => 'string'],
        ];

        if ($required) {
            $parameter['required'] = true;
        }

        if ($pair['value'] !== '') {
            $parameter['example'] = $pair['value'];
        }

        // A disabled row is still worth keeping: it is a call you nearly make.
        if (($pair['enabled'] ?? true) === false) {
            $parameter[self::EXTENSION] = ['enabled' => false];
        }

        return $parameter;
    }

    /** @param  array<string, mixed>  $operation */
    private static function request(string $path, string $verb, array $operation): SavedRequest
    {
        $extra = is_array($operation[self::EXTENSION] ?? null) ? $operation[self::EXTENSION] : [];
        $parameters = is_array($operation['parameters'] ?? null) ? $operation['parameters'] : [];

        $body = $operation['requestBody']['content']['application/json']['example'] ?? null;
        $json = match (true) {
            is_string($body) => $body,
            $body === null => '',
            default => (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        };

        return SavedRequest::fromArray([
            'name' => $extra['name'] ?? ($operation['summary'] ?? ''),
            // A summary that is only the name again was written by us to fill
            // OpenAPI's field; handing it back would turn one round trip into a
            // change in the file.
            'summary' => ($operation['summary'] ?? '') === ($extra['name'] ?? null)
                ? ''
                : ($operation['summary'] ?? ''),
            'method' => $verb,
            'path' => $path,
            'pathParams' => self::pairsIn($parameters, 'path'),
            'query' => self::pairsIn($parameters, 'query'),
            'headers' => self::pairsIn($parameters, 'header'),
            'bodyMode' => $extra['bodyMode'] ?? ($json !== '' ? 'json' : 'none'),
            'json' => $json,
            'auth' => $extra['auth'] ?? 'none',
        ]);
    }

    /**
     * @param  array<int, mixed>  $parameters
     * @return array<int, array{key: string, value: string, enabled: bool}>
     */
    private static function pairsIn(array $parameters, string $in): array
    {
        $pairs = [];

        foreach ($parameters as $parameter) {
            if (! is_array($parameter) || ($parameter['in'] ?? '') !== $in) {
                continue;
            }

            $pairs[] = [
                'key' => (string) ($parameter['name'] ?? ''),
                'value' => (string) ($parameter['example'] ?? ''),
                'enabled' => (bool) ($parameter[self::EXTENSION]['enabled'] ?? true),
            ];
        }

        return $pairs;
    }
}
