<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use App\Lottery\Grid;
use App\Lottery\GridStore;
use App\Lottery\PrizeTable;
use PHPUnit\Framework\TestCase;

final class GridStoreTest extends TestCase
{
    private string $projectDir;
    private GridStore $store;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/lfl-grid-store-'.bin2hex(random_bytes(6));
        $this->store = GridStore::inDirectory(new EuromillionsGame(), $this->projectDir.'/var/euromillions');
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

    public function testHistoryListsEveryDrawSinceCreationNewestFirst(): void
    {
        $game = new EuromillionsGame();
        $saved = $this->store->add([1, 9, 23, 34, 48], [3, 7]);
        $before = new Draw($saved->createdAt->modify('-3 days'), Grid::of($game, [1, 9, 23, 34, 48], [3, 7]));
        $first = new Draw($saved->createdAt, Grid::of($game, [1, 9, 23, 40, 41], [3, 11])); // 3 + 1 → tier 9
        $second = new Draw($saved->createdAt->modify('+3 days'), Grid::of($game, [2, 4, 6, 8, 10], [1, 2]));

        $history = $this->store->history($saved, [$before, $first, $second], new PrizeTable($game));

        self::assertCount(2, $history);
        self::assertSame($second, $history[0]['draw']);
        self::assertNull($history[0]['rank']);
        self::assertSame(3, $history[1]['matchedMain']);
        self::assertSame(1, $history[1]['matchedBonus']);
        self::assertSame(9, $history[1]['rank']);
    }

    public function testStoresAndChecksAMultipleGrid(): void
    {
        $game = new EuromillionsGame();
        $saved = $this->store->add([16, 23, 27, 32, 38, 41, 44], [2, 5, 12]);
        self::assertSame(63, $saved->grid->combinations($game));
        self::assertTrue($saved->grid->isMultiple($game));
        // Read back from disk, still multiple.
        self::assertCount(7, $this->store->all()[0]->grid->main);

        // 4 numbers and both stars among the ticked ones.
        $draw = new Draw($saved->createdAt, Grid::of($game, [16, 27, 32, 41, 45], [2, 12]));
        $history = $this->store->history($saved, [$draw], new PrizeTable($game));

        self::assertSame(4, $history[0]['rank']);
        self::assertSame([4 => 3, 5 => 6, 6 => 12, 8 => 6, 9 => 24, 12 => 12], $history[0]['wins']);
        self::assertGreaterThan(0.0, $history[0]['amount']);
    }

    public function testADrawPublishedLateIsStillChecked(): void
    {
        $game = new EuromillionsGame();
        $table = new PrizeTable($game);
        $saved = $this->store->add([1, 9, 23, 34, 48], [3, 7]);
        $winning = Grid::of($game, [1, 9, 23, 34, 48], [3, 7]);
        $friday = new Draw($saved->createdAt->modify('+3 days'), Grid::of($game, [2, 4, 6, 8, 10], [1, 2]));
        $tuesday = new Draw($saved->createdAt->modify('+7 days'), $winning);

        // The sync after Tuesday's draw only knows Friday's: Tuesday is not published yet.
        self::assertSame([], $this->store->findWins([$friday], $table)[0]['results']);
        $this->store->markChecked($friday);

        // Tuesday's results arrive later, whatever the day: they are reported once.
        $report = $this->store->findWins([$friday, $tuesday], $table);
        self::assertCount(1, $report[0]['results']);
        self::assertSame(1, $report[0]['results'][0]['rank']);
        $this->store->markChecked($tuesday);
        self::assertSame([], $this->store->findWins([$friday, $tuesday], $table)[0]['results']);

        // Marking an older draw never moves the mark back.
        $this->store->markChecked($friday);
        self::assertEquals($tuesday->date, $this->store->all()[0]->lastDrawChecked);
    }

    public function testADrawHeldBeforeTheGridWasSavedIsNeverReported(): void
    {
        $game = new EuromillionsGame();
        $table = new PrizeTable($game);
        $saved = $this->store->add([1, 9, 23, 34, 48], [3, 7]);
        $friday = new Draw($saved->createdAt->modify('-5 days'), Grid::of($game, [2, 4, 6, 8, 10], [1, 2]));
        // Held the day before the grid was saved, published after the first check.
        $tuesday = new Draw($saved->createdAt->modify('-1 day'), Grid::of($game, [1, 9, 23, 34, 48], [3, 7]));

        $this->store->markChecked($friday);

        self::assertSame([], $this->store->findWins([$friday, $tuesday], $table)[0]['results']);
    }

    public function testAFileFromAnOlderVersionKeepsItsCheckMark(): void
    {
        $game = new EuromillionsGame();
        mkdir($this->projectDir.'/var/euromillions', 0775, true);
        // Older versions stored the day of the last check: draws from that day on were still pending.
        file_put_contents($this->projectDir.'/var/euromillions/my-grids.json', json_encode([
            ['id' => 1, 'numbers' => [1, 9, 23, 34, 48], 'stars' => [3, 7], 'createdAt' => '2026-01-01', 'lastCheckedAt' => '2026-03-10'],
        ], \JSON_THROW_ON_ERROR));
        $winning = Grid::of($game, [1, 9, 23, 34, 48], [3, 7]);
        $alreadyReported = new Draw(new \DateTimeImmutable('2026-03-06'), $winning);
        $sameDay = new Draw(new \DateTimeImmutable('2026-03-10'), $winning);

        $results = $this->store->findWins([$alreadyReported, $sameDay], new PrizeTable($game))[0]['results'];

        self::assertCount(1, $results);
        self::assertSame('10/03/2026', $results[0]['date']);
    }

    public function testAddManyWritesOnceAndSkipsDuplicates(): void
    {
        $game = new EuromillionsGame();
        $this->store->add([1, 9, 23, 34, 48], [3, 7]);

        $added = $this->store->addMany([
            Grid::of($game, [1, 9, 23, 34, 48], [3, 7]),   // already saved
            Grid::of($game, [2, 4, 6, 8, 10], [1, 2]),
            Grid::of($game, [10, 8, 6, 4, 2], [2, 1]),     // the same, in another order
            Grid::multiple($game, [5, 15, 25, 35, 45, 50], [11, 12]),
        ]);

        self::assertSame([2, 3], array_map(static fn ($g): int => $g->id, $added));
        self::assertCount(3, $this->store->all());
        self::assertSame([], $this->store->addMany([]));
    }

    public function testRemovingAnUnknownGridDoesNotRewriteTheFile(): void
    {
        $this->store->add([1, 9, 23, 34, 48], [3, 7]);
        $file = $this->projectDir.'/var/euromillions/my-grids.json';
        touch($file, 1_000_000_000);
        clearstatcache();

        self::assertFalse($this->store->remove(42));
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime($file));
    }

    public function testRejectsOversizedGrids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->add([1, 2, 3, 4, 5], [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13]);
    }
}
