<?php

declare(strict_types=1);

namespace NetOs\Debug;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel;
use NetOs\Debug\Http\Middleware\SendRequestToNetosDebug;
use NetOs\Debug\Support\PayloadSizeLimiter;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NetOsDebugServiceProvider extends PackageServiceProvider
{
    /**
     * Debugbar's defaults do not satisfy the payload contract. `with_params` is
     * the one that matters most: left on, debugbar substitutes bindings into the
     * SQL string, and the Requests view loses both its bindings toggle and its
     * copyable statement.
     */
    private const array DEBUGBAR_OPTIONS = [
        'debugbar.collectors.route' => true,
        'debugbar.options.db.with_params' => false,
        'debugbar.options.db.backtrace' => true,
        'debugbar.options.db.timeline' => true,
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('netos-debug')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(
            PayloadSizeLimiter::class,
            fn ($app): PayloadSizeLimiter => new PayloadSizeLimiter(
                (int) $app['config']->get('netos-debug.max_payload_bytes', 512 * 1_024),
            ),
        );

        if (! $this->isActive() || ! $this->config()->get('netos-debug.configure_debugbar', true)) {
            return;
        }

        // This has to happen while registering, not while booting. Debugbar
        // reads these during its own boot(), and the boot order of
        // auto-discovered providers is not guaranteed — but every provider
        // registers before anything boots.
        $this->config()->set(self::DEBUGBAR_OPTIONS);
    }

    public function packageBooted(): void
    {
        if ($this->app->runningInConsole() || ! $this->isActive()) {
            return;
        }

        $this->app->make(Kernel::class)->pushMiddleware(SendRequestToNetosDebug::class);
    }

    /**
     * Active when `enabled` says so outright, and otherwise when the current
     * environment is one of the configured ones.
     */
    private function isActive(): bool
    {
        $enabled = $this->config()->get('netos-debug.enabled');

        if ($enabled !== null) {
            return (bool) $enabled;
        }

        /** @var list<string> $environments */
        $environments = $this->config()->get('netos-debug.environments', []);

        return $this->app->environment($environments);
    }

    private function config(): Repository
    {
        /** @var Repository $config */
        $config = $this->app->make(Repository::class);

        return $config;
    }
}
