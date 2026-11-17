<?php

declare(strict_types=1);

namespace App\Tests\Lottery;

use App\Lottery\WinSampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

final class WinSamplerTest extends TestCase
{
    /**
     * @return iterable<string, array{int, float}>
     */
    public static function regimes(): iterable
    {
        yield 'few hits (geometric jumps)' => [1_040, 1 / 500];
        yield 'many hits (normal approximation)' => [832_000, 1 / 13];
    }

    #[DataProvider('regimes')]
    public function testFollowsTheBinomialMeanAndVariance(int $trials, float $p): void
    {
        $sampler = new WinSampler(new Randomizer(new Xoshiro256StarStar(42)));
        $samples = [];
        for ($i = 0; $i < 4_000; ++$i) {
            $samples[] = $sampler->sampleWins($trials, $p);
        }

        $mean = array_sum($samples) / \count($samples);
        $variance = array_sum(array_map(static fn (int $x): float => ($x - $mean) ** 2, $samples)) / (\count($samples) - 1);
        $expectedMean = $trials * $p;
        $expectedVariance = $expectedMean * (1 - $p);

        self::assertEqualsWithDelta($expectedMean, $mean, 4 * sqrt($expectedVariance / \count($samples)));
        self::assertEqualsWithDelta($expectedVariance, $variance, 0.1 * $expectedVariance);
    }

    public function testEdgeCases(): void
    {
        $sampler = new WinSampler();
        self::assertSame(0, $sampler->sampleWins(0, 0.5));
        self::assertSame(0, $sampler->sampleWins(100, 0.0));
        self::assertSame(100, $sampler->sampleWins(100, 1.0));
    }

    public function testCostDoesNotGrowWithTheNumberOfGrids(): void
    {
        $sampler = new WinSampler();
        $start = hrtime(true);
        for ($i = 0; $i < 10_000; ++$i) {
            $sampler->sampleWins(\PHP_INT_MAX >> 8, 0.3);
        }

        self::assertLessThan(1.0, (hrtime(true) - $start) / 1e9);
    }
}
