<?php

declare(strict_types=1);

namespace NetOs\Debug\Payloads;

use Spatie\Ray\Payloads\Payload;

/**
 * Carries one finished HTTP request to NetOS Debug over the ray channel.
 *
 * Ray's own `sendCustom()` hardcodes `type: "custom"`, which would land this in
 * the event list next to plain `ray()` calls. A dedicated type lets the desktop
 * app route it into the Requests view instead.
 */
class HttpRequestPayload extends Payload
{
    /**
     * @param  array<string, mixed>  $request
     */
    public function __construct(private readonly array $request) {}

    public function getType(): string
    {
        return 'netos_request';
    }

    /**
     * @return array<string, mixed>
     */
    public function getContent(): array
    {
        return $this->request;
    }
}
