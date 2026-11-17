<?php

declare(strict_types=1);

namespace App\Web;

use App\Lottery\GridStorage;
use App\Lottery\GridStorageFullException;

/**
 * Grids kept in a cookie of the visitor's browser, for a public deployment:
 * the server keeps nothing, and every visitor only ever sees their own grids.
 *
 * Compact, URL-safe encoding (a cookie holds 4 KB, about 120 simple grids):
 * "id.numbers.stars.createdAt" per grid, numbers joined by "-", grids by "_",
 * e.g. "1.1-9-23-34-48.3-7.20261117". The check mark of the scheduled sync is
 * not kept: it only exists for grids kept on the server.
 */
final class CookieGridStorage implements GridStorage
{
    /** What fits in a cookie once its name and attributes are counted. */
    public const MAX_LENGTH = 3_800;

    public function __construct(
        private readonly BrowserGrids $browser,
        private readonly string $slug,
    ) {
    }

    public function load(): array
    {
        $records = [];
        foreach (explode('_', $this->browser->read($this->slug) ?? '') as $item) {
            // Malformed items are skipped; GridStore then validates the numbers.
            if (1 === preg_match('/^(\d{1,6})\.(\d{1,2}(?:-\d{1,2})*)\.(\d{1,2}(?:-\d{1,2})*)\.(\d{4})(\d{2})(\d{2})$/', $item, $m)) {
                $records[] = [
                    'id' => $m[1],
                    'numbers' => explode('-', $m[2]),
                    'stars' => explode('-', $m[3]),
                    'createdAt' => $m[4].'-'.$m[5].'-'.$m[6],
                ];
            }
        }

        return $records;
    }

    public function save(array $records): void
    {
        $value = implode('_', array_map(static fn (array $r): string => implode('.', [
            $r['id'],
            implode('-', $r['numbers']),
            implode('-', $r['stars']),
            str_replace('-', '', $r['createdAt']),
        ]), $records));

        if (\strlen($value) > self::MAX_LENGTH) {
            throw new GridStorageFullException('The grids no longer fit in the browser cookie.');
        }

        $this->browser->write($this->slug, $value);
    }

    public function exclusively(callable $operation): mixed
    {
        // One visitor, one request at a time: nothing to lock.
        return $operation();
    }
}
