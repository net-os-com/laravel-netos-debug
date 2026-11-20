<?php

declare(strict_types=1);

namespace NetOs\Debug\Mocks;

final class MockValidator
{
    public const string ID = '/^[a-z0-9][a-z0-9-]*\z/';

    public const string SECRET_CONFIG = '/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+)+\z/';

    private const array METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private const array SIGNATURES = ['none', 'hmac-sha256', 'stripe'];

    /**
     * @param  array<string, mixed>  $service
     * @return list<string>
     */
    public function errors(array $service): array
    {
        $errors = [];

        if (($error = $this->nameError($service['name'] ?? null)) !== null) {
            $errors[] = $error;
        }

        $endpoints = $this->rows($service['endpoints'] ?? []);

        $this->ids($endpoints, 'Endpoint', $errors);

        foreach ($endpoints as $endpoint) {
            $label = 'Endpoint '.$this->text($endpoint['id'] ?? '');

            $this->request($endpoint, $label, $errors);

            $responses = $this->rows($endpoint['responses'] ?? []);
            $this->ids($responses, 'Response', $errors, $label);

            foreach ($responses as $response) {
                $this->response($response, $label.', response '.$this->text($response['id'] ?? ''), $errors);
            }
        }

        $webhooks = $this->rows($service['webhooks'] ?? []);
        $this->ids($webhooks, 'Webhook', $errors);

        foreach ($webhooks as $webhook) {
            $label = 'Webhook '.$this->text($webhook['id'] ?? '');

            $this->request($webhook, $label, $errors);
            $this->signature($webhook['signature'] ?? [], $label, $errors);

            if (! is_string($webhook['body'] ?? '')) {
                $errors[] = "{$label}: body must be text.";
            }
        }

        return $errors;
    }

    public function nameError(mixed $name): ?string
    {
        if (is_string($name) && preg_match(self::ID, $name) === 1) {
            return null;
        }

        return "Service name {$this->text($name)} must be a slug: a-z, 0-9 and -.";
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $errors
     */
    private function ids(array $rows, string $kind, array &$errors, string $within = ''): void
    {
        $seen = [];
        $where = $within === '' ? '' : " in {$within}";

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;

            if (! is_string($id) || preg_match(self::ID, $id) !== 1) {
                $errors[] = "{$kind} id {$this->text($id)} must be lowercase letters, digits and dashes{$where}.";

                continue;
            }

            if (isset($seen[$id])) {
                $errors[] = "{$kind} id {$id} is used twice{$where}.";
            }

            $seen[$id] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $errors
     */
    private function request(array $row, string $label, array &$errors): void
    {
        $method = $row['method'] ?? null;
        $path = $row['path'] ?? null;

        if (! is_string($method) || ! in_array($method, self::METHODS, true)) {
            $errors[] = "{$label}: method {$this->text($method)} is not one of ".implode(', ', self::METHODS).'.';
        }

        if (! is_string($path) || ! str_starts_with($path, '/')) {
            $errors[] = "{$label}: path {$this->text($path)} must start with /.";
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  list<string>  $errors
     */
    private function response(array $response, string $label, array &$errors): void
    {
        $status = $response['status'] ?? null;
        $delay = $response['delayMs'] ?? null;

        if (! is_int($status) || $status < 100 || $status > 599) {
            $errors[] = "{$label}: status {$this->text($status)} must be a whole number from 100 to 599.";
        }

        if (! is_int($delay) || $delay < 0 || $delay > 60_000) {
            $errors[] = "{$label}: delay {$this->text($delay)} must be a whole number from 0 to 60000 ms.";
        }

        if (! is_string($response['body'] ?? '')) {
            $errors[] = "{$label}: body must be text.";
        }
    }

    /** @param  list<string>  $errors */
    private function signature(mixed $signature, string $label, array &$errors): void
    {
        $signature = is_array($signature) ? $signature : [];
        $kind = $signature['kind'] ?? 'none';

        if (! is_string($kind) || ! in_array($kind, self::SIGNATURES, true)) {
            $errors[] = "{$label}: signature kind {$this->text($kind)} is not one of ".implode(', ', self::SIGNATURES).'.';

            return;
        }

        if ($kind === 'none') {
            return;
        }

        $config = $signature['secretConfig'] ?? '';

        if (! is_string($config) || preg_match(self::SECRET_CONFIG, $config) !== 1) {
            $errors[] = "{$label}: the secret must be a config key such as services.mollie.secret, never the secret itself.";
        }

        if ($kind === 'hmac-sha256' && ($signature['header'] ?? '') === '') {
            $errors[] = "{$label}: an hmac-sha256 signature needs a header name.";
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private function text(mixed $value): string
    {
        return is_scalar($value) ? "'".addcslashes((string) $value, "\0..\37")."'" : "'?'";
    }
}
