<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Grids kept as JSON in the lottery's data directory (my-grids.json).
 *
 * The web interface and the scheduled sync both write the file: every
 * read-modify-write holds an exclusive lock, and writes are atomic.
 */
final class FileGridStorage implements GridStorage
{
    public function __construct(
        private readonly string $dataDir,
    ) {
    }

    public function load(): array
    {
        $file = $this->file();
        $raw = is_file($file) ? @file_get_contents($file) : false;
        if (false === $raw) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    public function save(array $records): void
    {
        $file = $this->file();
        $tmp = $file.'.tmp';

        if (false === file_put_contents($tmp, json_encode($records, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT))
            || !rename($tmp, $file)) {
            throw new \RuntimeException('Unable to write the grids file.');
        }
    }

    public function exclusively(callable $operation): mixed
    {
        $lock = fopen($this->file().'.lock', 'c');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Unable to lock the grids file.');
        }

        try {
            return $operation();
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }
    }

    private function file(): string
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0775, true) && !is_dir($this->dataDir)) {
            throw new \RuntimeException(\sprintf('Unable to create "%s".', $this->dataDir));
        }

        return $this->dataDir.'/my-grids.json';
    }
}
