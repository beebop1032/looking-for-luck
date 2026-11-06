<?php

declare(strict_types=1);

namespace App\Euromillions;

use App\Lottery\Draw;
use App\Lottery\PrizeTable;
use App\Lottery\Statistics;

/**
 * Renders a compact, factual summary of everything the toolkit knows — draw
 * statistics, the chi-squared verdict, prize maths, the user's saved grids —
 * as grounding context for the optional AI assistant.
 */
final class InsightsSummary
{
    public function __construct(
        private readonly EuromillionsGame $game,
        private readonly Statistics $statistics,
        private readonly PrizeTable $prizeTable,
        private readonly GridStore $store,
    ) {
    }

    /**
     * @param list<Draw> $draws sorted oldest to newest
     */
    public function build(array $draws): string
    {
        $lines = [];
        $lines[] = '# EuroMillions data summary';

        if ([] !== $draws) {
            $first = $draws[0];
            $latest = $draws[array_key_last($draws)];
            $lines[] = \sprintf(
                'History: %d draws from %s to %s.',
                \count($draws),
                $first->date->format('Y-m-d'),
                $latest->date->format('Y-m-d'),
            );
            $lines[] = \sprintf('Latest draw (%s): %s.', $latest->date->format('Y-m-d'), $latest->grid->format());

            $lines[] = $this->rankingLine('Most drawn numbers', $this->statistics->mainFrequencies($draws), true);
            $lines[] = $this->rankingLine('Least drawn numbers', $this->statistics->mainFrequencies($draws), false);
            $lines[] = $this->gapsLine($this->statistics->mainGaps($draws));

            $starDraws = $this->game->drawsUnderCurrentStarRules($draws);
            $mainTest = $this->statistics->uniformityTest($this->statistics->mainFrequencies($draws));
            $starTest = $this->statistics->uniformityTest($this->statistics->bonusFrequencies($starDraws));
            $lines[] = \sprintf(
                'Chi-squared uniformity test — numbers: χ²=%.2f, p=%.4f (%s); stars since %s: χ²=%.2f, p=%.4f (%s).',
                $mainTest['statistic'],
                $mainTest['pValue'],
                $mainTest['compatible'] ? 'compatible with pure randomness' : 'unusual deviation',
                EuromillionsGame::STAR_RULES_SINCE,
                $starTest['statistic'],
                $starTest['pValue'],
                $starTest['compatible'] ? 'compatible with pure randomness' : 'unusual deviation',
            );
        }

        $lines[] = \sprintf(
            'Prize maths: probability of winning anything at one draw = 1/%.1f; expected prize per grid ≈ %.2f € for a %.2f € ticket (negative expected value).',
            1 / $this->prizeTable->anyWinProbability(),
            $this->prizeTable->expectedPrizePerGrid(),
            $this->game->defaultTicketPrice(),
        );
        $lines[] = 'Jackpot odds: 1 in 139,838,160. Every draw is independent; past frequencies have no predictive power.';

        $saved = $this->store->all();
        if ([] !== $saved) {
            $lines[] = \sprintf('The user has %d saved grid(s):', \count($saved));
            foreach ($saved as $grid) {
                $lines[] = \sprintf(' - Grid #%d: %s (saved %s).', $grid->id, $grid->grid->format(), $grid->createdAt->format('Y-m-d'));
            }
        } else {
            $lines[] = 'The user has no saved grids.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int,int> $frequencies
     */
    private function rankingLine(string $label, array $frequencies, bool $descending): string
    {
        $descending ? arsort($frequencies) : asort($frequencies);

        $parts = [];
        foreach (\array_slice($frequencies, 0, 5, true) as $value => $count) {
            $parts[] = \sprintf('%d (%d×)', $value, $count);
        }

        return \sprintf('%s: %s.', $label, implode(', ', $parts));
    }

    /**
     * @param array<int,int> $gaps
     */
    private function gapsLine(array $gaps): string
    {
        arsort($gaps);

        $parts = [];
        foreach (\array_slice($gaps, 0, 5, true) as $value => $gap) {
            $parts[] = \sprintf('%d (%d draws)', $value, $gap);
        }

        return \sprintf('Most overdue numbers: %s.', implode(', ', $parts));
    }
}
