<?php

declare(strict_types=1);

namespace App\Lottery;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Registers a lottery with the application: implement this interface (next to
 * your LotteryGame and DrawSource) and the lottery appears in the web
 * interface and in the scheduled sync, with no other wiring.
 */
#[AutoconfigureTag('app.lottery')]
interface LotteryDefinition
{
    /** URL and storage identifier, e.g. "euromillions" (lowercase, a-z0-9 and dashes). */
    public function slug(): string;

    public function game(): LotteryGame;

    public function drawSource(): DrawSource;

    /** Where the lottery is played, as flag emojis or a short region name, e.g. "🇪🇺". */
    public function region(): string;

    /** Colour of the lottery's "stage" (the dark band where draws are shown), e.g. "#13227a". Keep it dark: text on it is white. */
    public function accentColor(): string;

    /** How bonus numbers are drawn on screen: "star" (EuroMillions) or "ball" (Powerball…). */
    public function bonusShape(): string;

    /** Official website, where players must verify their winnings. */
    public function officialUrl(): string;
}
