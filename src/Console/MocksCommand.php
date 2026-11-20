<?php

declare(strict_types=1);

namespace NetOs\Debug\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use NetOs\Debug\Mocks\MockStore;
use NetOs\Debug\Mocks\MockValidator;
use RuntimeException;

/**
 * The desktop app's way in and out of the mock fixture files, speaking JSON on
 * stdout like the collections command.
 */
class MocksCommand extends Command
{
    protected $signature = 'netos-debug:mocks
        {action : list, save, forget or secret}
        {name? : service name or config key}
        {--hash= : the hash the caller last read}';

    protected $description = 'Read and write the mock fixtures in this repository';

    public function handle(MockStore $store, Repository $config): int
    {
        $action = (string) $this->argument('action');
        $name = (string) ($this->argument('name') ?? '');
        $hash = $this->option('hash');
        $hash = is_string($hash) && $hash !== '' ? $hash : null;

        if (in_array($action, ['forget', 'secret'], true) && $name === '') {
            return $this->refuse('That action needs a name.');
        }

        try {
            return match ($action) {
                'list' => $this->say($store->list() + ['directory' => $this->directory($store)]),
                'save' => $this->save($store, $hash),
                'forget' => $this->forget($store, $name, $hash),
                'secret' => $this->reveal($config, $name),
                default => $this->refuse("Unknown action '{$action}'."),
            };
        } catch (RuntimeException $e) {
            return $this->refuse($e->getMessage());
        }
    }

    private function save(MockStore $store, ?string $hash): int
    {
        $decoded = json_decode((string) file_get_contents('php://stdin'), true);

        if (! is_array($decoded)) {
            return $this->refuse('Expected one service as JSON on stdin.');
        }

        return $this->say($store->save($decoded, $hash));
    }

    private function forget(MockStore $store, string $name, ?string $hash): int
    {
        $store->forget($name, $hash);

        return $this->say([]);
    }

    private function reveal(Repository $config, string $key): int
    {
        if (preg_match(MockValidator::SECRET_CONFIG, $key) !== 1) {
            return $this->refuse('A secret is read by config key, such as services.mollie.secret.');
        }

        $value = $config->get($key);

        if (! is_string($value) || $value === '') {
            return $this->refuse("{$key} is empty in this application.");
        }

        return $this->say(['secret' => $value]);
    }

    private function directory(MockStore $store): string
    {
        $base = base_path().'/';
        $directory = $store->directory();

        return str_starts_with($directory, $base) ? substr($directory, strlen($base)) : $directory;
    }

    /** @param  array<string, mixed>  $payload */
    private function say(array $payload): int
    {
        $this->line((string) json_encode($payload + ['error' => null]));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode(['error' => $message]));

        return self::FAILURE;
    }
}
