<?php

declare(strict_types=1);

namespace NetOs\Debug\Payloads;

use Spatie\Ray\Payloads\Payload;

/**
 * Carries one outgoing HTTP call to NetOS Debug over the ray channel.
 *
 * Its own type, for the same reason a collected request has one: the desktop app
 * routes it to the HTTP client view instead of dropping it in the stream next to
 * plain `ray()` calls.
 */
class OutgoingRequestPayload extends Payload
{
    /**
     * @param  array<string, mixed>  $call
     */
    public function __construct(private readonly array $call) {}

    public function getType(): string
    {
        return 'netos_http_client';
    }

    /**
     * @return array<string, mixed>
     */
    public function getContent(): array
    {
        return $this->call;
    }
}
