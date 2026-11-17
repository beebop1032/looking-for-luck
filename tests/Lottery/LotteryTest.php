<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Euromillions\DrawRepository;
use App\Euromillions\EuromillionsDefinition;
use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use App\Lottery\DrawSource;
use App\Lottery\Grid;
use App\Lottery\GridInput;
use App\Lottery\LotteryDefinition;
use App\Lottery\LotteryGame;
use App\Lottery\LotteryRegistry;
use App\Tests\Fixtures\FiveOfSixtyNineGame;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;

final class LotteryTest extends TestCase
{
    private string $dataRoot;

    protected function setUp(): void
    {
        $this->dataRoot = sys_get_temp_dir().'/lfl-lottery-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataRoot.'/*/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->dataRoot.'/*') ?: [] as $dir) {
            @rmdir($dir);
        }
        @rmdir($this->dataRoot);
    }

    public function testRegistryBuildsOneLotteryPerDefinition(): void
    {
        $registry = new LotteryRegistry([$this->euromillions()], $this->dataRoot, [
            ['name' => 'EuroMillions', 'region' => '🇪🇺', 'issue' => 1],
            ['name' => 'Powerball', 'region' => '🇺🇸', 'issue' => 2],
        ]);

        self::assertCount(1, $registry->all());
        self::assertSame('EuroMillions', $registry->get('euromillions')->name());
        self::assertNull($registry->find('powerball'));
        // A planned lottery disappears from the roadmap once it is supported.
        self::assertSame(['Powerball'], array_column($registry->planned(), 'name'));
    }

    public function testEachLotteryKeepsItsGridsInItsOwnDirectory(): void
    {
        $registry = new LotteryRegistry([$this->euromillions(), $this->definition('test-ball', new FiveOfSixtyNineGame())], $this->dataRoot);

        $registry->get('euromillions')->grids()->add([1, 2, 3, 4, 5], [1, 2]);
        $registry->get('test-ball')->grids()->add([10, 20, 30, 40, 69], [26]);

        self::assertFileExists($this->dataRoot.'/euromillions/my-grids.json');
        self::assertFileExists($this->dataRoot.'/test-ball/my-grids.json');
        self::assertCount(1, $registry->get('test-ball')->grids()->all());
    }

    public function testRejectsInvalidOrDuplicateSlugs(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Two lotteries use the slug "euromillions"');

        new LotteryRegistry([$this->euromillions(), $this->euromillions()], $this->dataRoot);
    }

    public function testJackpotOddsComeFromTheMatrix(): void
    {
        $registry = new LotteryRegistry([$this->euromillions(), $this->definition('test-ball', new FiveOfSixtyNineGame())], $this->dataRoot);

        self::assertEqualsWithDelta(139_838_160, $registry->get('euromillions')->jackpotOdds(), 0.5);
        // The official Powerball odds: the engine needs no Powerball-specific code.
        self::assertEqualsWithDelta(292_201_338, $registry->get('test-ball')->jackpotOdds(), 0.5);
    }

    public function testGridInputFollowsTheMatrix(): void
    {
        $input = new GridInput(new FiveOfSixtyNineGame());

        self::assertSame('5-17-60-62-69|26', $input->parse('69 5 17 60 62 + 26')->signature());
        self::assertCount(5, $input->parse($input->example())->main);
        self::assertSame(1, preg_match('/^'.$input->htmlPattern().'$/', $input->example()));

        $this->expectException(\InvalidArgumentException::class);
        $input->parse('1 2 3 4 5 + 1 2'); // two bonus numbers: EuroMillions format
    }

    public function testSyncIsDueOnlyAfterAnUnknownRecentDraw(): void
    {
        $draws = [new Draw(new \DateTimeImmutable('2026-11-13'), Grid::of(new EuromillionsGame(), [1, 2, 3, 4, 5], [1, 2]))];
        $lottery = (new LotteryRegistry([$this->definition('euromillions', new EuromillionsGame(), $draws)], $this->dataRoot))->get('euromillions');
        $paris = new \DateTimeZone('Europe/Paris');
        $synchronizer = $lottery->synchronizer();

        // Never synced: always due.
        self::assertTrue($synchronizer->isDue(new \DateTimeImmutable('2026-11-16 12:00', $paris)));

        $synchronizer->sync(); // knows the draw of Friday 13

        self::assertFalse($synchronizer->isDue(new \DateTimeImmutable('2026-11-16 12:00', $paris)), 'Monday: nothing new');
        self::assertFalse($synchronizer->isDue(new \DateTimeImmutable('2026-11-17 20:00', $paris)), 'Tuesday before the draw');
        self::assertTrue($synchronizer->isDue(new \DateTimeImmutable('2026-11-17 22:30', $paris)), 'Tuesday after the draw');
        // Weeks later, the last draw (Friday 4 Dec) is almost 4 days old: wait for tonight's.
        self::assertFalse($synchronizer->isDue(new \DateTimeImmutable('2026-12-08 20:00', $paris)), 'last draw older than 3 days');
        self::assertTrue($synchronizer->isDue(new \DateTimeImmutable('2026-12-08 22:00', $paris)), 'a new draw just took place');
    }

    private function euromillions(): EuromillionsDefinition
    {
        $game = new EuromillionsGame();

        return new EuromillionsDefinition($game, new DrawRepository(new MockHttpClient(), $game, $this->dataRoot.'/euromillions'));
    }

    /**
     * @param list<Draw> $draws
     */
    private function definition(string $slug, LotteryGame $game, array $draws = []): LotteryDefinition
    {
        $source = new class($draws) implements DrawSource {
            /** @param list<Draw> $draws */
            public function __construct(private readonly array $draws)
            {
            }

            public function all(?int $fromYear = null): array
            {
                return $this->draws;
            }

            public function refresh(): array
            {
                return $this->draws;
            }

            public function firstYear(): int
            {
                return 2020;
            }
        };

        return new class($slug, $game, $source) implements LotteryDefinition {
            public function __construct(private readonly string $slug, private readonly LotteryGame $game, private readonly DrawSource $source)
            {
            }

            public function slug(): string
            {
                return $this->slug;
            }

            public function game(): LotteryGame
            {
                return $this->game;
            }

            public function drawSource(): DrawSource
            {
                return $this->source;
            }

            public function region(): string
            {
                return '🏳️';
            }

            public function accentColor(): string
            {
                return '#000000';
            }

            public function bonusShape(): string
            {
                return 'ball';
            }

            public function officialUrl(): string
            {
                return 'https://example.org';
            }
        };
    }
}
