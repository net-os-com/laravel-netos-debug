<?php

declare(strict_types=1);

namespace NetOs\Debug;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use NetOs\Debug\Collectors\HttpClientRecorder;
use NetOs\Debug\Console\CollectionsCommand;
use NetOs\Debug\Console\ErdCommand;
use NetOs\Debug\Http\Middleware\SendRequestToNetosDebug;
use NetOs\Debug\Support\PayloadSizeLimiter;
use NetOs\Debug\Support\WorkContext;
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
        // Both feed panels the Requests view already ships. Debugbar leaves
        // them off, which is why the Cache tab and the authenticated-user card
        // were arriving empty rather than merely unused.
        'debugbar.collectors.cache' => true,
        'debugbar.collectors.auth' => true,
        'debugbar.options.db.with_params' => false,
        'debugbar.options.db.backtrace' => true,
        'debugbar.options.db.timeline' => true,
    ];

    public function configurePackage(Package $package): void
    {
        $package
            ->name('netos-debug')
            ->hasConfigFile()
            ->hasCommand(CollectionsCommand::class)
            ->hasCommand(ErdCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(
            PayloadSizeLimiter::class,
            fn ($app): PayloadSizeLimiter => new PayloadSizeLimiter(
                (int) $app['config']->get('netos-debug.max_payload_bytes', 512 * 1_024),
            ),
        );

        // Both hold state across the events they answer — what is in flight,
        // and which job is being processed — so neither can be rebuilt per
        // event.
        $this->app->singleton(WorkContext::class);
        $this->app->singleton(HttpClientRecorder::class);

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
        if (! $this->isActive()) {
            return;
        }

        // Before the console check, not after it: a queued job refreshing a
        // token and a command talking to an upstream are exactly the calls that
        // are hardest to see from anywhere else.
        $this->watchOutgoingCalls();

        if ($this->app->runningInConsole()) {
            return;
        }

        $this->app->make(Kernel::class)->pushMiddleware(SendRequestToNetosDebug::class);
    }

    /**
     * Laravel's own client fires these three around every call it makes, and the
     * queue fires the other two around every job — which is how a call made
     * inside one can say so.
     */
    private function watchOutgoingCalls(): void
    {
        if (! $this->config()->get('netos-debug.http_client.enabled', true)) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);

        $events->listen(RequestSending::class, [HttpClientRecorder::class, 'sending']);
        $events->listen(ResponseReceived::class, [HttpClientRecorder::class, 'received']);
        $events->listen(ConnectionFailed::class, [HttpClientRecorder::class, 'failed']);

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(WorkContext::class)->enterJob($event->job->resolveName());
        });

        $events->listen([JobProcessed::class, JobFailed::class], function (): void {
            $this->app->make(WorkContext::class)->leaveJob();
        });
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
