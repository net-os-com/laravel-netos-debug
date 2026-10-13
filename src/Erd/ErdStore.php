<?php

declare(strict_types=1);

namespace NetOs\Debug\Erd;

use Illuminate\Contracts\Config\Repository;

/**
 * Owns where a project keeps its schema diagram.
 *
 * The path lives here rather than in the desktop app for the same reason the
 * collections path does: the app knows about diagrams, the package knows where
 * this repository keeps things, and the file has to land inside the bind mount
 * that git is watching.
 */
class ErdStore
{
    public function __construct(private Repository $config) {}

    /** The directory the two files live in, absolute. */
    public function directory(): string
    {
        $path = (string) $this->config->get('netos-debug.erd.path', 'database');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    public function schemaFile(): string
    {
        return $this->directory().'/schema.dbml';
    }

    public function layoutFile(): string
    {
        return $this->directory().'/schema.layout.json';
    }

    /**
     * Both files are written together so the two can never describe different
     * schemas.
     *
     * @param  array<string, mixed>  $layout
     * @param  list<mixed>  $stickies
     * @return list<string>
     */
    public function save(string $dbml, array $layout, array $stickies = []): array
    {
        if (! is_dir($this->directory())) {
            mkdir($this->directory(), 0o755, true);
        }

        file_put_contents($this->schemaFile(), $dbml);
        file_put_contents(
            $this->layoutFile(),
            json_encode(
                ['tables' => $layout, 'stickies' => $stickies, 'savedAt' => date(DATE_ATOM)],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            )."\n"
        );

        return [$this->relative($this->schemaFile()), $this->relative($this->layoutFile())];
    }

    /**
     * @return array{dbml: string, layout: array<string, mixed>, stickies: list<mixed>, savedAt: string|null}
     */
    public function read(): array
    {
        $raw = is_file($this->layoutFile()) ? file_get_contents($this->layoutFile()) : false;
        $decoded = $raw === false ? null : json_decode($raw, true);

        if (! is_array($decoded)) {
            return ['dbml' => $this->dbml(), 'layout' => [], 'stickies' => [], 'savedAt' => null];
        }

        return [
            'dbml' => $this->dbml(),
            'layout' => is_array($decoded['tables'] ?? null) ? $decoded['tables'] : [],
            // Absent in a layout file written before notes existed.
            'stickies' => is_array($decoded['stickies'] ?? null) ? array_values($decoded['stickies']) : [],
            'savedAt' => is_string($decoded['savedAt'] ?? null) ? $decoded['savedAt'] : null,
        ];
    }

    /**
     * The committed diagram itself.
     *
     * It is read back, unlike when this file was write-only: the editor opens
     * on what the repository holds rather than making you import first.
     */
    private function dbml(): string
    {
        $raw = is_file($this->schemaFile()) ? file_get_contents($this->schemaFile()) : false;

        return $raw === false ? '' : $raw;
    }

    /** Reported relative to the project, which is how a person refers to it. */
    private function relative(string $file): string
    {
        $base = base_path().'/';

        return str_starts_with($file, $base) ? mb_substr($file, mb_strlen($base)) : $file;
    }
}
