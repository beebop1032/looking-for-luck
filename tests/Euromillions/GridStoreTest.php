<?php

declare(strict_types=1);

namespace App\Tests\Euromillions;

use App\Euromillions\EuromillionsGame;
use App\Euromillions\GridStore;
use PHPUnit\Framework\TestCase;

final class GridStoreTest extends TestCase
{
    private string $projectDir;
    private GridStore $store;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/lfl-grid-store-'.bin2hex(random_bytes(6));
        $this->store = new GridStore(new EuromillionsGame(), $this->projectDir);
    }

    protected function tearDown(): void
    {
        $dir = $this->projectDir.'/var/euromillions';
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        @rmdir($this->projectDir.'/var');
        @rmdir($this->projectDir);
    }

    public function testAddAndList(): void
    {
        $saved = $this->store->add([1, 9, 23, 34, 48], [3, 7]);

        self::assertSame(1, $saved->id);
        self::assertCount(1, $this->store->all());
        self::assertSame('1-9-23-34-48|3-7', $this->store->all()[0]->grid->signature());
    }

    public function testRejectsDuplicateGrid(): void
    {
        $this->store->add([1, 9, 23, 34, 48], [3, 7]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already saved as #1');

        // Same grid, different input order: still a duplicate.
        $this->store->add([48, 34, 23, 9, 1], [7, 3]);
    }

    public function testDifferentStarsAreNotADuplicate(): void
    {
        $this->store->add([1, 9, 23, 34, 48], [3, 7]);
        $saved = $this->store->add([1, 9, 23, 34, 48], [3, 8]);

        self::assertSame(2, $saved->id);
        self::assertCount(2, $this->store->all());
    }

    public function testRemove(): void
    {
        $this->store->add([1, 9, 23, 34, 48], [3, 7]);

        self::assertTrue($this->store->remove(1));
        self::assertSame([], $this->store->all());
        self::assertFalse($this->store->remove(1));
    }
}
