<?php

declare(strict_types=1);

namespace NetOs\Debug\Collections;

/**
 * One saved call, in the shape both sides agree on.
 *
 * The desktop app knows about requests and the package knows about OpenAPI, so
 * this is the only vocabulary they share: everything here survives a round trip
 * through a spec file, and nothing here is a secret. Values that resolve at send
 * time — a token, anything behind `{{ braces }}` — are stored as the reference
 * rather than as what it stands for, which is what makes these files safe to
 * commit.
 */
final readonly class SavedRequest
{
    /**
     * @param  array<int, array{key: string, value: string, enabled?: bool}>  $query
     * @param  array<int, array{key: string, value: string, enabled?: bool}>  $headers
     * @param  array<int, array{key: string, value: string}>  $pathParams
     */
    public function __construct(
        public string $name,
        public string $method,
        public string $path,
        public array $pathParams = [],
        public array $query = [],
        public array $headers = [],
        public string $bodyMode = 'none',
        public string $json = '',
        /** `none`, `bearer` or `impersonate` — never who, and never the token. */
        public string $auth = 'none',
        public string $summary = '',
    ) {}

    /** @param  array<string, mixed>  $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            name: self::text($raw['name'] ?? '') ?: 'Untitled request',
            method: mb_strtoupper(self::text($raw['method'] ?? 'GET')) ?: 'GET',
            path: self::path(self::text($raw['path'] ?? '/')),
            pathParams: self::pairs($raw['pathParams'] ?? []),
            query: self::pairs($raw['query'] ?? []),
            headers: self::pairs($raw['headers'] ?? []),
            bodyMode: self::mode(self::text($raw['bodyMode'] ?? 'none')),
            json: self::text($raw['json'] ?? ''),
            auth: self::authMode(self::text($raw['auth'] ?? 'none')),
            summary: self::text($raw['summary'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'method' => $this->method,
            'path' => $this->path,
            'pathParams' => $this->pathParams,
            'query' => $this->query,
            'headers' => $this->headers,
            'bodyMode' => $this->bodyMode,
            'json' => $this->json,
            'auth' => $this->auth,
            'summary' => $this->summary,
        ];
    }

    /**
     * The id an operation is stored and found under. Derived rather than
     * generated, so saving the same call twice replaces it instead of piling up
     * near-duplicates.
     */
    public function operationId(): string
    {
        $slug = mb_strtolower($this->method.$this->path);
        $slug = (string) preg_replace('~[^a-z0-9]+~', '-', $slug);

        return trim($slug, '-') ?: 'request';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** A path is stored with its leading slash, however it arrived. */
    private static function path(string $value): string
    {
        return $value === '' ? '/' : '/'.ltrim($value, '/');
    }

    private static function mode(string $value): string
    {
        return in_array($value, ['none', 'json', 'form'], true) ? $value : 'none';
    }

    private static function authMode(string $value): string
    {
        return in_array($value, ['none', 'bearer', 'impersonate'], true) ? $value : 'none';
    }

    /**
     * @return array<int, array{key: string, value: string, enabled: bool}>
     */
    private static function pairs(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $pairs = [];

        foreach ($raw as $one) {
            if (! is_array($one)) {
                continue;
            }

            $key = self::text($one['key'] ?? '');

            if ($key === '') {
                continue;
            }

            $pairs[] = [
                'key' => $key,
                'value' => self::text($one['value'] ?? ''),
                'enabled' => (bool) ($one['enabled'] ?? true),
            ];
        }

        return $pairs;
    }
}
