<?php

declare(strict_types=1);

namespace NetOs\Debug\Shapers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use NetOs\Debug\Support\WorkContext;

/**
 * Turns one outgoing call into the shape the desktop app reads.
 *
 * Two things happen here that the rest of the collector does not do. Headers are
 * redacted, because an outgoing call carries the credential for whatever it is
 * calling and this payload leaves the process; and bodies are truncated, because
 * a page of JSON from an upstream API is worth seeing and a 40MB export is not.
 */
final readonly class OutgoingRequestShaper
{
    /** Header values that are never shipped, whatever they contain. */
    private const array ALWAYS_REDACTED = [
        'authorization',
        'proxy-authorization',
        'cookie',
        'set-cookie',
    ];

    public function __construct(
        private Repository $config,
        private WorkContext $context,
    ) {}

    /**
     * @param  string|null  $error  The reason the call never got an answer.
     * @param  array<int, array{function: string, file: string, line: int, vendor: bool}>  $trace
     * @return array<string, mixed>
     */
    public function shape(
        Request $request,
        ?Response $response,
        ?string $error,
        float $startedAt,
        float $endedAt,
        array $trace = [],
    ): array {
        $url = $request->url();

        return [
            'method' => $request->method(),
            'url' => $url,
            'host' => (string) (parse_url($url, PHP_URL_HOST) ?: ''),
            'path' => (string) (parse_url($url, PHP_URL_PATH) ?: '/'),
            'query' => (string) (parse_url($url, PHP_URL_QUERY) ?: ''),
            'requestHeaders' => $this->headers($request->headers()),
            'requestBody' => $this->body($request->body()),
            'status' => $response?->status() ?? 0,
            'reason' => $response?->reason() ?? '',
            'responseHeaders' => $response === null ? [] : $this->headers($response->headers()),
            'responseBody' => $response === null ? '' : $this->body($response->body()),
            'error' => $error,
            // Milliseconds, measured around the event pair rather than taken
            // from curl: it is the wait the application actually had.
            'durationMs' => (int) round(($endedAt - $startedAt) * 1000),
            'startedAt' => (int) round($startedAt * 1000),
            // An object even when it is empty: `[]` would arrive as a JSON array
            // and the app reads this as a map of phase to milliseconds.
            'timing' => (object) $this->timing($response),
            'context' => $this->context->describe(),
            'tenant' => $this->tenant(),
            'trace' => $trace,
        ];
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<int, array{key: string, value: string}>
     */
    private function headers(array $headers): array
    {
        $out = [];

        foreach ($headers as $key => $value) {
            $joined = is_array($value) ? implode(', ', $value) : (string) $value;

            $out[] = [
                'key' => (string) $key,
                'value' => $this->isSecret((string) $key)
                    // Said rather than hidden: that a call carries a token is
                    // half of what you are looking for, and how long it is tells
                    // you which kind. The token itself is not.
                    ? '‹redacted, '.mb_strlen($joined).' characters›'
                    : $joined,
            ];
        }

        return $out;
    }

    private function isSecret(string $key): bool
    {
        $name = mb_strtolower($key);

        if (in_array($name, self::ALWAYS_REDACTED, true)) {
            return true;
        }

        /** @var list<string> $extra */
        $extra = $this->config->get('netos-debug.http_client.redact', []);

        foreach ($extra as $pattern) {
            if (fnmatch(mb_strtolower($pattern), $name)) {
                return true;
            }
        }

        return false;
    }

    private function body(string $body): string
    {
        $max = (int) $this->config->get('netos-debug.http_client.max_body_bytes', 16 * 1_024);

        if ($max <= 0 || mb_strlen($body, '8bit') <= $max) {
            return $body;
        }

        return mb_strcut($body, 0, $max)."\n\n‹truncated at {$max} bytes›";
    }

    /**
     * Which tenant was current, in an application that has them.
     *
     * Asked for rather than required: this package does not depend on a tenancy
     * package, so it looks for the helper the application would have if it had
     * one, and says nothing when there is none. A tenancy that throws while
     * resolving is a tenancy that was not active.
     */
    private function tenant(): ?string
    {
        if (! function_exists('tenant')) {
            return null;
        }

        try {
            $tenant = tenant();

            if ($tenant === null) {
                return null;
            }

            $key = method_exists($tenant, 'getTenantKey') ? $tenant->getTenantKey() : null;

            return is_scalar($key) && (string) $key !== '' ? (string) $key : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * What curl measured, when curl is what did the work. A faked response or
     * another handler leaves this empty rather than guessing zeroes.
     *
     * @return array<string, float>
     */
    private function timing(?Response $response): array
    {
        $stats = $response?->handlerStats() ?? [];
        $wanted = [
            'dns' => 'namelookup_time',
            'connect' => 'connect_time',
            'tls' => 'appconnect_time',
            'firstByte' => 'starttransfer_time',
            'total' => 'total_time',
        ];

        $timing = [];

        foreach ($wanted as $name => $key) {
            if (isset($stats[$key]) && is_numeric($stats[$key])) {
                // Seconds from curl, milliseconds everywhere in this app.
                $timing[$name] = round(((float) $stats[$key]) * 1000, 1);
            }
        }

        // The one phase curl does not time directly: everything after the first
        // byte is the body coming down.
        if (isset($timing['total'], $timing['firstByte'])) {
            $timing['download'] = round(max(0, $timing['total'] - $timing['firstByte']), 1);
        }

        return $timing;
    }
}
