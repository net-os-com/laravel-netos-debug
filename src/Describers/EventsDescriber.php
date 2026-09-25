<?php

declare(strict_types=1);

namespace NetOs\Debug\Describers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Str;

/**
 * Groups the events a request fired, with the listeners that handled them.
 *
 * Debugbar records every single dispatch — six hundred on a warm request, most
 * of them `eloquent.booting` — so they are folded by name, the way the design
 * shows them, rather than shipped one row per dispatch.
 *
 * Listeners come from the dispatcher rather than from debugbar: debugbar only
 * records them when it also records every event's arguments, which is far more
 * data than this is worth.
 */
class EventsDescriber
{
    /** Enough to show the shape of a request without shipping a novel. */
    private const int MAX_EVENTS = 150;

    public function __construct(private readonly Dispatcher $events) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function describe(array $data): array
    {
        $event = is_array($data['event'] ?? null) ? $data['event'] : [];
        $measures = is_array($event['measures'] ?? null) ? $event['measures'] : [];
        $listeners = $this->listeners();
        $grouped = [];

        foreach ($measures as $measure) {
            $measure = (array) $measure;
            $name = (string) ($measure['label'] ?? '');

            if ($name === '') {
                continue;
            }

            if (! isset($grouped[$name])) {
                $grouped[$name] = [
                    'name' => $name,
                    'count' => 0,
                    'offsetMs' => round((float) ($measure['relative_start'] ?? 0) * 1_000, 3),
                    'listeners' => $this->listenersFor($name, $listeners),
                ];
            }

            $grouped[$name]['count'] += 1;
        }

        return array_slice(array_values($grouped), 0, self::MAX_EVENTS);
    }

    /**
     * The dispatcher's raw registrations, before it wraps them in closures.
     * Roughly a quarter are genuine closures with no name worth showing; the
     * rest resolve to the class that handles the event.
     *
     * @return array<string, list<string>>
     */
    private function listeners(): array
    {
        if (! $this->events instanceof EventDispatcher) {
            return [];
        }

        $resolved = [];

        foreach ($this->events->getRawListeners() as $event => $listeners) {
            $names = array_values(array_filter(array_map(
                fn (mixed $listener): ?string => $this->name($listener),
                is_array($listeners) ? $listeners : [$listeners],
            )));

            if ($names !== []) {
                $resolved[(string) $event] = $names;
            }
        }

        return $resolved;
    }

    /**
     * Wildcard registrations such as `eloquent.*` handle events whose name is
     * only known at dispatch, so the lookup matches patterns as well as names.
     *
     * @param  array<string, list<string>>  $listeners
     * @return list<string>
     */
    private function listenersFor(string $event, array $listeners): array
    {
        $names = $listeners[$event] ?? [];

        foreach ($listeners as $pattern => $registered) {
            if (str_contains($pattern, '*') && Str::is($pattern, $event)) {
                $names = array_merge($names, $registered);
            }
        }

        return array_values(array_unique($names));
    }

    private function name(mixed $listener): ?string
    {
        if (is_string($listener)) {
            return class_basename(explode('@', $listener)[0]);
        }

        if (is_array($listener) && isset($listener[0])) {
            $class = is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];

            return class_basename($class);
        }

        // A closure has no name worth showing, and a row of "Closure" chips
        // tells the reader nothing.
        return null;
    }
}
