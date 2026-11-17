<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * Optional: a DrawSource that also knows the jackpot announced for the next
 * draw implements this interface. The web interface then shows it instead of
 * the last draw's jackpot.
 */
interface NextJackpotSource
{
    /**
     * The announced jackpot of the next draw, or null when unknown. Must not
     * throw: a failure simply means "unknown".
     */
    public function nextJackpot(bool $forceRefresh = false): ?NextJackpot;
}
