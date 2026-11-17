<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * The matrix and prize structure of a lottery game.
 *
 * Everything the generic engine (grid generation, prize maths, simulations,
 * strategy replay) needs to know about a lottery. Implement this interface to
 * add a new lottery: EuroMillions picks 5/50 main numbers and 2/12 bonus
 * numbers ("stars"), Powerball picks 5/69 and 1/26, etc.
 */
interface LotteryGame
{
    public function name(): string;

    /**
     * Size of the main number pool (e.g. 50 for EuroMillions).
     *
     * @return positive-int
     */
    public function mainPool(): int;

    /**
     * How many main numbers a grid picks (e.g. 5).
     *
     * @return positive-int
     */
    public function mainPicked(): int;

    /**
     * Size of the bonus number pool (e.g. 12 EuroMillions stars).
     *
     * @return positive-int
     */
    public function bonusPool(): int;

    /**
     * How many bonus numbers a grid picks (e.g. 2).
     *
     * @return positive-int
     */
    public function bonusPicked(): int;

    /** What the game calls its bonus numbers ("Stars", "Powerball", ...). */
    public function bonusLabel(): string;

    public function drawsPerWeek(): int;

    /**
     * Draw days, ISO-8601 (1 = Monday … 7 = Sunday), e.g. [2, 5] for Tuesday and Friday.
     *
     * @return non-empty-list<int<1, 7>>
     */
    public function drawDays(): array;

    /** Local draw time, "HH:MM", in drawTimezone(). */
    public function drawTime(): string;

    /** Timezone of the draw, e.g. "Europe/Paris" or "America/New_York". */
    public function drawTimezone(): string;

    /** ISO 4217 currency of tickets and prizes, e.g. "EUR" or "USD". */
    public function currency(): string;

    /**
     * Date of the last change of the matrix (pool sizes), if any. Statistics
     * that compare frequencies (χ² test, heatmaps) only use draws held under
     * the current rules, otherwise they would merely detect the rule change.
     */
    public function currentRulesSince(): ?\DateTimeImmutable;

    /** Number of columns on the paper play slip (for visual-pattern detection). */
    public function slipColumns(): int;

    public function defaultTicketPrice(): float;

    public function defaultJackpot(): float;

    public function minimumJackpot(): float;

    /** The jackpot cap (EuroMillions: 250 million euros); simulations never exceed it. */
    public function maximumJackpot(): float;

    /**
     * The winning tiers, best first.
     *
     * @return list<PrizeTier>
     */
    public function prizeTiers(): array;
}
