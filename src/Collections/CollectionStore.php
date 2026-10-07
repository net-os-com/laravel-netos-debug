<?php

declare(strict_types=1);

namespace NetOs\Debug\Collections;

use Illuminate\Contracts\Config\Repository;
use Symfony\Component\Yaml\Yaml;

/**
 * Saved requests, on disk, in the application's own repository.
 *
 * They live here rather than in the desktop app's storage so they can be
 * reviewed, branched and merged like everything else the team owns — a request
 * that reproduces a bug is worth as much as the test that covers it, and it
 * should not be in one person's laptop.
 *
 * YAML rather than JSON because these files are read in diffs: a changed header
 * is one changed line, not a re-indented block.
 */
final readonly class CollectionStore
{
    public function __construct(private Repository $config) {}

    /**
     * Every collection, with what is in it.
     *
     * The contents come along rather than being fetched one call at a time:
     * each call from the desktop app is a `docker exec` that boots the
     * framework, so ten collections would have been eleven boots and eleven
     * seconds before the sidebar could draw.
     *
     * @return array<int, array{name: string, file: string, operations: int, requests: array<int, array<string, mixed>>}>
     */
    public function list(): array
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            return [];
        }

        $collections = [];

        foreach (glob($directory.'/*.yaml') ?: [] as $file) {
            $name = basename($file, '.yaml');
            $requests = $this->read($name);

            $collections[] = [
                'name' => $name,
                'file' => $this->relative($file),
                'operations' => count($requests),
                'requests' => array_map(
                    fn (SavedRequest $one): array => $one->toArray() + ['operationId' => $one->operationId()],
                    $requests,
                ),
            ];
        }

        usort($collections, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $collections;
    }

    /** @return array<int, SavedRequest> */
    public function read(string $name): array
    {
        $file = $this->file($name);

        if (! is_file($file)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($file);
        } catch (\Throwable) {
            // A file someone hand-edited into invalid YAML is their business;
            // refusing to start the tab over it is not.
            return [];
        }

        return is_array($parsed) ? OpenApiDocument::toRequests($parsed) : [];
    }

    /**
     * Adds or replaces one request, keyed by its verb and path.
     *
     * @return array{file: string, operations: int}
     */
    public function save(string $name, SavedRequest $request): array
    {
        $kept = array_values(array_filter(
            $this->read($name),
            fn (SavedRequest $one): bool => $one->operationId() !== $request->operationId(),
        ));

        $kept[] = $request;

        return $this->write($name, $kept);
    }

    /** @return array{file: string, operations: int} */
    public function forget(string $name, string $operationId): array
    {
        return $this->write($name, array_values(array_filter(
            $this->read($name),
            fn (SavedRequest $one): bool => $one->operationId() !== $operationId,
        )));
    }

    /**
     * @param  array<int, SavedRequest>  $requests
     * @return array{file: string, operations: int}
     */
    private function write(string $name, array $requests): array
    {
        $file = $this->file($name);

        // An emptied collection is deleted rather than written out. An OpenAPI
        // document with no paths is both invalid — `paths` is an object, and an
        // empty PHP array dumps as a sequence — and a file nobody wants in a
        // diff.
        if ($requests === []) {
            if (is_file($file)) {
                unlink($file);
            }

            return ['file' => $this->relative($file), 'operations' => 0];
        }

        $directory = dirname($file);

        if (! is_dir($directory)) {
            mkdir($directory, 0o755, true);
        }

        $document = OpenApiDocument::fromRequests($this->title($name), $requests);

        file_put_contents($file, Yaml::dump($document, 8, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE));

        return ['file' => $this->relative($file), 'operations' => count($requests)];
    }

    /** The directory the files live in, absolute. */
    public function directory(): string
    {
        $path = (string) $this->config->get('netos-debug.collections.path', 'openapi');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    /**
     * A collection name becomes a file name, so it is reduced to something that
     * cannot leave the directory it is meant to be in.
     */
    private function file(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = (string) preg_replace('~[^a-z0-9._-]+~', '-', $slug);
        $slug = trim($slug, '-.');

        return $this->directory().'/'.($slug === '' ? 'requests' : $slug).'.yaml';
    }

    private function title(string $name): string
    {
        return ucfirst(str_replace('-', ' ', $name));
    }

    /** Paths are reported relative to the application, which is what git shows. */
    private function relative(string $file): string
    {
        $base = base_path().'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
