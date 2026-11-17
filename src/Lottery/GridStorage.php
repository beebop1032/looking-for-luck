<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Where "My grids" are kept: a file on the server (FileGridStorage) or, on a
 * public deployment, a cookie in each visitor's browser. Storages only keep
 * records; GridStore validates everything it reads back.
 *
 * @phpstan-type GridRecord array{id: int, numbers: list<int>, stars: list<int>, createdAt: string, lastDrawChecked: ?string}
 */
interface GridStorage
{
    /**
     * @return array<mixed> the stored records, unvalidated ([] when there are none or they are unreadable)
     */
    public function load(): array;

    /**
     * @param list<GridRecord> $records
     *
     * @throws GridStorageFullException when the records do not fit
     */
    public function save(array $records): void;

    /**
     * Runs a read-modify-write so that no other writer interleaves.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function exclusively(callable $operation): mixed;
}
