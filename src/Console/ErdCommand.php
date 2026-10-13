<?php

declare(strict_types=1);

namespace NetOs\Debug\Console;

use Illuminate\Console\Command;
use NetOs\Debug\Erd\ErdStore;

/**
 * The desktop app's way in and out of the schema diagram files.
 *
 * A command rather than an HTTP route, for the reason written down in
 * CollectionsCommand: a write endpoint on the API would be reachable by
 * anything that can reach the API, while a command is reachable by whoever can
 * already run code in the container.
 */
class ErdCommand extends Command
{
    protected $signature = 'netos-debug:erd {action : save or read}';

    protected $description = 'Read and write the schema diagram in this repository';

    public function handle(ErdStore $store): int
    {
        return match ((string) $this->argument('action')) {
            'read' => $this->say($store->read()),
            'save' => $this->save($store),
            default => $this->refuse('Use save or read.'),
        };
    }

    private function save(ErdStore $store): int
    {
        $payload = json_decode((string) file_get_contents('php://stdin'), true);

        if (! is_array($payload) || ! is_string($payload['dbml'] ?? null)) {
            return $this->refuse('Expected {"dbml": string, "layout": object} on stdin.');
        }

        $layout = is_array($payload['layout'] ?? null) ? $payload['layout'] : [];
        $stickies = is_array($payload['stickies'] ?? null) ? array_values($payload['stickies']) : [];

        return $this->say(['files' => $store->save($payload['dbml'], $layout, $stickies)]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function say(array $data): int
    {
        $this->line((string) json_encode($data + ['error' => null], JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode(['error' => $message]));

        return self::FAILURE;
    }
}
