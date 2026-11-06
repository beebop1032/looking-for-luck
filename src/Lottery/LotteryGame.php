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

    /** Size of the main number pool (e.g. 50 for EuroMillions). */
    public function mainPool(): int;

    /** How many main numbers a grid picks (e.g. 5). */
    public function mainPicked(): int;

    /** Size of the bonus number pool (e.g. 12 EuroMillions stars). */
    public function bonusPool(): int;

    /** How many bonus numbers a grid picks (e.g. 2). */
    public function bonusPicked(): int;

    /** What the game calls its bonus numbers ("Stars", "Powerball", ...). */
    public function bonusLabel(): string;

    public function drawsPerWeek(): int;

    /** Number of columns on the paper play slip (for visual-pattern detection). */
    public function slipColumns(): int;

    public function defaultTicketPrice(): float;

    public function defaultJackpot(): float;

    public function minimumJackpot(): float;

    /**
     * The winning tiers, best first.
     *
     * @return list<PrizeTier>
     */
    public function prizeTiers(): array;
}
