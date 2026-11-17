<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * A registered lottery with its engine wired to its own game, draw source and
 * data directory. Built by LotteryRegistry from a LotteryDefinition.
 */
final class Lottery
{
    private ?PrizeTable $prizeTable = null;
    private ?GridGenerator $generator = null;
    private ?GridStore $grids = null;
    private ?SyncStatus $syncStatus = null;

    public function __construct(
        public readonly LotteryDefinition $definition,
        private readonly string $dataDir,
    ) {
    }

    public function slug(): string
    {
        return $this->definition->slug();
    }

    public function name(): string
    {
        return $this->game()->name();
    }

    public function game(): LotteryGame
    {
        return $this->definition->game();
    }

    public function draws(): DrawSource
    {
        return $this->definition->drawSource();
    }

    public function prizeTable(): PrizeTable
    {
        return $this->prizeTable ??= new PrizeTable($this->game());
    }

    public function generator(): GridGenerator
    {
        return $this->generator ??= new GridGenerator($this->game());
    }

    public function filter(): PopularityFilter
    {
        return new PopularityFilter($this->game());
    }

    public function statistics(): Statistics
    {
        return new Statistics($this->game(), new ChiSquare());
    }

    public function lab(): StrategyLab
    {
        return new StrategyLab($this->game(), $this->prizeTable(), $this->generator());
    }

    public function budget(): BudgetSimulator
    {
        return new BudgetSimulator($this->game(), $this->prizeTable());
    }

    public function groupPlay(): GroupPlay
    {
        return new GroupPlay($this->game(), $this->prizeTable());
    }

    public function grids(): GridStore
    {
        return $this->grids ??= new GridStore($this->game(), $this->dataDir);
    }

    public function gridInput(): GridInput
    {
        return new GridInput($this->game());
    }

    public function syncStatus(): SyncStatus
    {
        return $this->syncStatus ??= new SyncStatus($this->dataDir);
    }

    public function synchronizer(): Synchronizer
    {
        return new Synchronizer($this->game(), $this->draws(), $this->grids(), $this->prizeTable(), $this->syncStatus());
    }

    public function nextDraw(\DateTimeImmutable $now = new \DateTimeImmutable()): \DateTimeImmutable
    {
        return DrawSchedule::nextAfter($this->game(), $now);
    }

    /**
     * The jackpot announced for the next draw, when the draw source knows it
     * and it really is for the next scheduled draw.
     */
    public function nextJackpot(bool $forceRefresh = false): ?NextJackpot
    {
        $source = $this->draws();
        if (!$source instanceof NextJackpotSource) {
            return null;
        }

        $jackpot = $source->nextJackpot($forceRefresh);
        $next = $this->nextDraw();

        return null !== $jackpot && $jackpot->drawDate->format('Y-m-d') === $next->format('Y-m-d') ? $jackpot : null;
    }

    /** Odds of the top prize, e.g. 139838160 for EuroMillions. */
    public function jackpotOdds(): float
    {
        return 1 / $this->prizeTable()->probability($this->game()->mainPicked(), $this->game()->bonusPicked());
    }

    /**
     * Draws held under the current matrix rules (see LotteryGame::currentRulesSince()).
     *
     * @param list<Draw> $draws
     *
     * @return list<Draw>
     */
    public function drawsUnderCurrentRules(array $draws): array
    {
        return self::underCurrentRules($this->game(), $draws);
    }

    /**
     * @param list<Draw> $draws
     *
     * @return list<Draw>
     */
    public static function underCurrentRules(LotteryGame $game, array $draws): array
    {
        $since = $game->currentRulesSince();
        if (null === $since) {
            return $draws;
        }

        return array_values(array_filter($draws, static fn (Draw $d): bool => $d->date >= $since));
    }
}
