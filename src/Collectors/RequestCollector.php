<?php

declare(strict_types=1);

namespace NetOs\Debug\Collectors;

use Fruitcake\LaravelDebugbar\LaravelDebugbar;
use Illuminate\Http\Request;
use NetOs\Debug\Explain\QueryPlanner;
use NetOs\Debug\Payloads\HttpRequestPayload;
use NetOs\Debug\Shapers\RequestShaper;
use NetOs\Debug\Support\PayloadSizeLimiter;
use Spatie\LaravelRay\Ray;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads one finished request out of debugbar and hands it to NetOS Debug.
 *
 * Debugbar has normally already collected by this point, on the RequestHandled
 * event; `getData()` collects lazily if it has not, so both paths are safe.
 */
class RequestCollector
{
    public function __construct(
        private readonly LaravelDebugbar $debugbar,
        private readonly RequestShaper $shaper,
        private readonly QueryPlanner $planner,
        private readonly PayloadSizeLimiter $limiter,
        private readonly Ray $ray,
    ) {}

    public function collect(Request $request, Response $response): void
    {
        if (! $this->debugbar->isEnabled()) {
            return;
        }

        $data = $this->debugbar->getData();
        $shaped = $this->planner->attach($this->shaper->shape($data, $request, $response), $data);

        $this->ray->sendRequest([new HttpRequestPayload($this->limiter->limit($shaped))]);
    }
}
