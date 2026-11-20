<?php

declare(strict_types=1);

namespace NetOs\Debug\Mocks;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Mock fixtures for third-party APIs, on disk, in the application's own
 * repository — one YAML file per service, reviewed like the code that calls it.
 *
 * Every file carries a hash so a save from the desktop app cannot silently
 * overwrite an edit made in an editor or arriving through a merge.
 */
final readonly class MockStore
{
    public function __construct(
        private Repository $config,
        private MockValidator $validator,
    ) {}

    /**
     * @return array{services: list<array<string, mixed>>, problems: list<array{file: string, error: string}>}
     */
    public function list(): array
    {
        $services = [];
        $problems = [];

        foreach (glob($this->directory().'/*.yaml') ?: [] as $file) {
            $name = basename($file, '.yaml');

            try {
                if (preg_match(MockValidator::ID, $name) !== 1) {
                    throw new RuntimeException('File name must be a slug: a-z, 0-9 and -.');
                }

                $parsed = Yaml::parseFile($file);

                if (! is_array($parsed)) {
                    throw new RuntimeException('The file is not a YAML mapping.');
                }

                $service = MockDocument::toContract($name, $parsed);
                $errors = $this->validator->errors($service);

                if ($errors !== []) {
                    throw new RuntimeException($errors[0]);
                }
            } catch (\Throwable $e) {
                $problems[] = ['file' => $this->relative($file), 'error' => $e->getMessage()];

                continue;
            }

            $services[] = $service + ['file' => $this->relative($file), 'hash' => $this->hash($file)];
        }

        usort($services, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['services' => $services, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{file: string, hash: string}
     */
    public function save(array $contract, ?string $expectedHash): array
    {
        $file = $this->file((string) ($contract['name'] ?? ''));

        $this->guard($file, $expectedHash);

        $errors = $this->validator->errors($contract);

        if ($errors !== []) {
            throw new RuntimeException($errors[0]);
        }

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0o755, true);
        }

        file_put_contents($file, Yaml::dump(
            MockDocument::toYaml($contract),
            10,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK,
        ));

        return ['file' => $this->relative($file), 'hash' => $this->hash($file)];
    }

    public function forget(string $name, ?string $expectedHash): void
    {
        $file = $this->file($name);

        $this->guard($file, $expectedHash);

        if (! is_file($file)) {
            throw new RuntimeException($this->relative($file).' is gone. Reload, then save again.');
        }

        unlink($file);

        if (count(scandir(dirname($file)) ?: []) === 2) {
            rmdir(dirname($file));
        }
    }

    /** The directory the files live in, absolute. */
    public function directory(): string
    {
        $path = (string) $this->config->get('netos-debug.mocks.path', 'mocks');

        return str_starts_with($path, '/') ? $path : base_path($path);
    }

    private function guard(string $file, ?string $expectedHash): void
    {
        $relative = $this->relative($file);

        if (is_file($file) && $expectedHash !== $this->hash($file)) {
            throw new RuntimeException("{$relative} changed on disk. Reload, then save again.");
        }

        if (! is_file($file) && $expectedHash !== null) {
            throw new RuntimeException("{$relative} is gone. Reload, then save again.");
        }
    }

    private function hash(string $file): string
    {
        return (string) sha1_file($file);
    }

    /**
     * A service name becomes a file name, so it is reduced to something that
     * cannot leave the directory it is meant to be in.
     */
    private function file(string $name): string
    {
        $slug = mb_strtolower(trim($name));
        $slug = (string) preg_replace('~[^a-z0-9._-]+~', '-', $slug);
        $slug = trim($slug, '-.');

        return $this->directory().'/'.($slug === '' ? 'mock' : $slug).'.yaml';
    }

    private function relative(string $file): string
    {
        $base = base_path().'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
