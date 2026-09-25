<?php

declare(strict_types=1);

namespace NetOs\Debug\Describers;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Describes the route a request matched, for the Requests view's Route tab.
 *
 * Debugbar's own route collector is thin — it reports the middleware as one
 * comma-joined string of aliases and groups — so this reads the matched Route
 * directly and lets the Router resolve the stack, which yields the classes that
 * actually ran, in order. Only the source location is taken from debugbar,
 * which has already done the reflection to find it.
 */
class RouteDescriber
{
    public function __construct(private readonly Router $router) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public function describe(Request $request, array $data): ?array
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return null;
        }

        $middleware = $this->middleware($route);
        $collected = is_array($data['route'] ?? null) ? $data['route'] : [];

        return [
            'uri' => $route->uri(),
            'methods' => array_values($route->methods()),
            'name' => $route->getName(),
            'action' => $this->action($route),
            'file' => $this->text($collected['file'] ?? null),
            'prefix' => mb_trim((string) $route->getPrefix(), '/') ?: null,
            'domain' => $route->getDomain(),
            'parameters' => $this->parameters($route),
            'throttle' => $this->throttle($middleware),
            'middleware' => $middleware,
        ];
    }

    /**
     * The Router expands groups, resolves aliases and drops excluded middleware,
     * so what comes back is the stack that ran rather than what was written on
     * the route. The short name is the class basename plus any parameters, which
     * is how the stack reads in the design.
     *
     * @return list<array{name: string, class: string|null}>
     */
    private function middleware(Route $route): array
    {
        $entries = [];

        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                $entries[] = ['name' => Closure::class, 'class' => null];

                continue;
            }

            $separator = mb_strpos($middleware, ':');
            $class = $separator === false ? $middleware : mb_substr($middleware, 0, $separator);
            $parameters = $separator === false ? null : mb_substr($middleware, $separator + 1);
            $short = class_basename($class);

            $entries[] = [
                'name' => $parameters === null ? $short : $short.':'.$parameters,
                'class' => class_exists($class) ? $class : null,
            ];
        }

        return $entries;
    }

    /**
     * Rate limits are registered as closures that need a request to evaluate, so
     * only the limiter the route names is reported, not the number behind it.
     *
     * @param  list<array{name: string, class: string|null}>  $middleware
     */
    private function throttle(array $middleware): ?string
    {
        foreach ($middleware as $entry) {
            if ($entry['class'] === null || ! str_contains($entry['class'], 'ThrottleRequests')) {
                continue;
            }

            $parameters = explode(':', $entry['name'], 2)[1] ?? null;

            return $parameters === null ? null : str_replace(',', ' · ', $parameters);
        }

        return null;
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function parameters(Route $route): array
    {
        $parameters = [];

        // The original values, so a model-bound parameter reports the segment
        // from the URL rather than a dump of the model it resolved to.
        foreach ($route->originalParameters() as $key => $value) {
            $parameters[] = ['key' => (string) $key, 'value' => is_scalar($value) ? (string) $value : ''];
        }

        return $parameters;
    }

    private function action(Route $route): ?string
    {
        $action = $route->getAction();
        $controller = $action['controller'] ?? null;

        if (is_string($controller) && $controller !== '') {
            return $controller;
        }

        return ($action['uses'] ?? null) instanceof Closure ? Closure::class : null;
    }

    private function text(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value['value'] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
