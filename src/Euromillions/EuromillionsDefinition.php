<?php

declare(strict_types=1);

namespace App\Euromillions;

use App\Lottery\DrawSource;
use App\Lottery\LotteryDefinition;
use App\Lottery\LotteryGame;

/**
 * Registers EuroMillions with the web interface and the scheduled sync.
 */
final class EuromillionsDefinition implements LotteryDefinition
{
    public function __construct(
        private readonly EuromillionsGame $game,
        private readonly DrawRepository $draws,
    ) {
    }

    public function slug(): string
    {
        return 'euromillions';
    }

    public function game(): LotteryGame
    {
        return $this->game;
    }

    public function drawSource(): DrawSource
    {
        return $this->draws;
    }

    public function region(): string
    {
        return '🇪🇺';
    }

    public function accentColor(): string
    {
        return '#13227a';
    }

    public function bonusShape(): string
    {
        return 'star';
    }

    public function officialUrl(): string
    {
        return 'https://www.euro-millions.com';
    }
}
