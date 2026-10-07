<?php

declare(strict_types=1);

namespace NetOs\Debug\Console;

use Illuminate\Console\Command;
use NetOs\Debug\Collections\CollectionStore;
use NetOs\Debug\Collections\SavedRequest;

/**
 * The desktop app's way in and out of the collection files.
 *
 * It goes through a command rather than through an HTTP route on purpose: the
 * app already runs `php artisan` in the container for everything else, and a
 * write endpoint on the API would be reachable by anything that can reach the
 * API. A command is reachable by whoever can already run code in the container,
 * which is a line that has already been crossed by the time you are here.
 *
 * Everything speaks JSON on stdout so the caller never has to parse prose.
 */
class CollectionsCommand extends Command
{
    protected $signature = 'netos-debug:collections
        {action : list, read, save or forget}
        {collection? : the collection to act on}
        {operation? : the operation id, for forget}';

    protected $description = 'Read and write the saved API requests in this repository';

    public function handle(CollectionStore $store): int
    {
        $action = (string) $this->argument('action');
        $collection = (string) ($this->argument('collection') ?? '');

        if ($action !== 'list' && $collection === '') {
            return $this->refuse('That action needs a collection name.');
        }

        return match ($action) {
            'list' => $this->say(['collections' => $store->list(), 'directory' => $store->directory()]),
            'read' => $this->say([
                'requests' => array_map(
                    fn (SavedRequest $one): array => $one->toArray() + ['operationId' => $one->operationId()],
                    $store->read($collection),
                ),
            ]),
            'save' => $this->save($store, $collection),
            'forget' => $this->say($store->forget($collection, (string) ($this->argument('operation') ?? ''))),
            default => $this->refuse("Unknown action '{$action}'."),
        };
    }

    /**
     * The request arrives on stdin rather than as an argument: a body is bigger
     * than a command line is allowed to be, and quoting one through a shell is
     * a way to corrupt it quietly.
     */
    private function save(CollectionStore $store, string $collection): int
    {
        $raw = (string) file_get_contents('php://stdin');
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return $this->refuse('Expected one request as JSON on stdin.');
        }

        return $this->say($store->save($collection, SavedRequest::fromArray($decoded)));
    }

    /** @param  array<string, mixed>  $payload */
    private function say(array $payload): int
    {
        $this->line((string) json_encode($payload + ['error' => null]));

        return self::SUCCESS;
    }

    /** Named around Laravel's own fail(), which throws rather than reports. */
    private function refuse(string $message): int
    {
        $this->line((string) json_encode(['error' => $message]));

        return self::FAILURE;
    }
}
