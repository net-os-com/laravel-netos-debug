<?php

declare(strict_types=1);

namespace NetOs\Debug\Support;

use Illuminate\Contracts\Foundation\Application;

/**
 * Where the code that is running right now was started from.
 *
 * An outgoing call is almost never interesting on its own — what matters is
 * what was being done at the time. A call to the identity provider during
 * `POST /login` is a login; the same call from a queued job is a token refresh;
 * the same call from `php artisan` is somebody debugging. The page can only say
 * which if the collector records it, and only this side knows.
 */
final class WorkContext
{
    /** The job being processed, while one is. */
    private ?string $job = null;

    public function __construct(private readonly Application $app) {}

    /** Set by the queue listeners, so a call can name the job that made it. */
    public function enterJob(string $name): void
    {
        $this->job = $name;
    }

    public function leaveJob(): void
    {
        $this->job = null;
    }

    /**
     * @return array{kind: 'request'|'queue'|'console'|'unknown', name: string|null}
     */
    public function describe(): array
    {
        if ($this->job !== null) {
            return ['kind' => 'queue', 'name' => $this->job];
        }

        if ($this->app->runningInConsole()) {
            return ['kind' => 'console', 'name' => $this->command()];
        }

        $request = $this->request();

        return $request === null
            ? ['kind' => 'unknown', 'name' => null]
            : ['kind' => 'request', 'name' => $request];
    }

    /** `POST /api/bookings`, or null when there is no request to speak of. */
    private function request(): ?string
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return $request->getMethod().' '.$request->getPathInfo();
    }

    /**
     * The command line, without the arguments: a signature is useful, somebody's
     * `--password=` on the next argument is not.
     */
    private function command(): ?string
    {
        $argv = $_SERVER['argv'] ?? null;

        if (! is_array($argv) || ! isset($argv[1]) || ! is_string($argv[1])) {
            return null;
        }

        return $argv[1];
    }
}
