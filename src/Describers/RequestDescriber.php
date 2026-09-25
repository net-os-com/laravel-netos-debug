<?php

declare(strict_types=1);

namespace NetOs\Debug\Describers;

use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository as ContextRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request's own data for the Requests view: query parameters, headers and
 * whatever the application put on Laravel's Context.
 *
 * Everything here is pushed over the network to a desktop app and then held in
 * its buffer, so credentials are masked before they leave the process rather
 * than hidden in the UI.
 */
class RequestDescriber
{
    /** Headers whose value is a credential, whatever it happens to contain. */
    private const array SECRET_HEADERS = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'x-auth-token',
        'x-csrf-token',
        'x-xsrf-token',
        'php-auth-pw',
    ];

    /** Key fragments that mark a value as a secret wherever it appears. */
    private const array SECRET_HINTS = ['token', 'secret', 'password', 'signature', 'api_key', 'apikey'];

    /** A response body is for reading, not for archiving. */
    private const int MAX_BODY_BYTES = 64 * 1_024;

    public function __construct(private readonly ContextRepository $context) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function describe(Request $request, Response $response, array $data): array
    {
        $collected = is_array($data['request'] ?? null) ? ($data['request']['data'] ?? []) : [];
        $collected = is_array($collected) ? $collected : [];

        return [
            'queryParameters' => $this->pairs($this->flatten($request->query())),
            'headers' => $this->joined($request->headers->all()),
            'context' => $this->pairs($this->flatten($this->context->all())),
            'responseHeaders' => $this->joined($response->headers->all()),
            'responseBody' => $this->body($response),
            'session' => $this->pairs($this->flatten((array) ($collected['session_attributes'] ?? []))),
            'auth' => $this->auth($data),
        ];
    }

    /**
     * JSON only. An HTML error page is 64 KB of Ignition markup that says
     * nothing the Stream view does not already show properly, and it would
     * crowd out the rest of the payload.
     */
    private function body(Response $response): ?string
    {
        $type = (string) $response->headers->get('Content-Type', '');

        if (! preg_match('~^application/(json|.*\+json)~i', $type)) {
            return null;
        }

        $content = $response->getContent();

        if (! is_string($content) || $content === '') {
            return null;
        }

        return mb_strlen($content) > self::MAX_BODY_BYTES
            ? mb_substr($content, 0, self::MAX_BODY_BYTES)."\n… truncated"
            : $content;
    }

    /**
     * Debugbar's auth collector reports a name per guard, and null for the
     * guards nobody signed in with.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{key: string, value: string}>
     */
    private function auth(array $data): array
    {
        $auth = is_array($data['auth'] ?? null) ? $data['auth'] : [];
        $guards = $auth['guards'] ?? [];
        $signedIn = [];

        foreach ((array) $guards as $guard => $user) {
            if ($user === null || $user === '') {
                continue;
            }

            $signedIn[] = ['key' => (string) $guard, 'value' => strip_tags((string) $user)];
        }

        return $signedIn;
    }

    /**
     * Headers arrive as name => [values]. They are joined rather than flattened
     * to `name[0]`, both because that is how a header reads and because the
     * bracket suffix would walk straight past the mask list — `set-cookie[0]`
     * is not `set-cookie`, and the session cookie would ship in the clear.
     *
     * @param  array<string, array<int, string|null>>  $all
     * @return list<array{key: string, value: string}>
     */
    private function joined(array $all): array
    {
        $headers = [];

        foreach ($all as $name => $values) {
            $headers[(string) $name] = implode(', ', array_filter((array) $values, 'is_string'));
        }

        ksort($headers);

        return $this->pairs($headers);
    }

    /**
     * Nested values become bracketed keys, the way they were written in the URL,
     * so `filter[date]` reads as itself rather than as a JSON blob.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, string>
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => 'null',
                is_scalar($value) => (string) $value,
                default => get_debug_type($value),
            };
        }

        return $flat;
    }

    /**
     * @param  array<string, string>  $values
     * @return list<array{key: string, value: string}>
     */
    private function pairs(array $values): array
    {
        $pairs = [];

        foreach ($values as $key => $value) {
            $pairs[] = ['key' => $key, 'value' => $this->mask($key, $value)];
        }

        return $pairs;
    }

    /**
     * Keeps the scheme, drops the credential: `Bearer ••••••` still tells you
     * which scheme was used without carrying the token off the machine.
     */
    private function mask(string $key, string $value): string
    {
        // Any index suffix a flatten added is stripped first, so a mangled key
        // can never be the reason a credential goes out in the clear.
        $key = mb_strtolower(preg_replace('~\[.*$~', '', $key) ?? $key);
        $secret = in_array($key, self::SECRET_HEADERS, true);

        foreach (self::SECRET_HINTS as $hint) {
            $secret = $secret || str_contains($key, $hint);
        }

        if (! $secret || $value === '') {
            return $value;
        }

        // Only a real auth scheme is worth keeping — `Bearer`, `Basic`. Anything
        // else before the first space is part of the secret: a Set-Cookie reads
        // `name=value; path=/`, so keeping the first token would have published
        // the cookie and masked the path.
        $first = explode(' ', $value, 2)[0];
        $scheme = preg_match('~^[A-Za-z]+$~', $first) === 1 ? $first.' ' : '';

        return $scheme.str_repeat('•', 12);
    }
}
