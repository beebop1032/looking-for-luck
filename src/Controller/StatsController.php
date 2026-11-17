<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\Lottery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class StatsController extends AbstractPageController
{
    #[Route('/{lottery}/stats', name: 'stats', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['GET'])]
    public function index(Lottery $lottery, Request $request): Response
    {
        $game = $lottery->game();
        $statistics = $lottery->statistics();
        $all = $this->draws($lottery) ?? [];
        $first = [] === $all ? new \DateTimeImmutable('today') : $all[0]->date;
        $last = [] === $all ? new \DateTimeImmutable('today') : $all[array_key_last($all)]->date;

        // An exact date range, clamped to the known history.
        $from = max($first, min($last, $this->dateQuery($request, 'from') ?? $first));
        $to = min($last, max($from, $this->dateQuery($request, 'to') ?? $last));
        $draws = array_values(array_filter($all, static fn ($d): bool => $d->date >= $from && $d->date <= $to));

        $rulesSince = $game->currentRulesSince();
        $presets = [
            'all' => ['from' => $first, 'to' => $last],
            'rules' => null !== $rulesSince && $rulesSince > $first ? ['from' => $rulesSince, 'to' => $last] : null,
            'twelve_months' => ['from' => $last->modify('-1 year +1 day'), 'to' => $last],
            'this_year' => ['from' => $last->modify('first day of january this year'), 'to' => $last],
        ];

        $common = [
            'lottery' => $lottery,
            'draws' => $draws,
            'from' => $from,
            'to' => $to,
            'first' => $first,
            'last' => $last,
            'presets' => array_filter($presets),
        ];
        if (\count($draws) < 50) {
            return $this->render('stats.html.twig', $common + ['enough' => false]);
        }

        // Frequencies are only comparable under constant rules (pool sizes).
        $ruleDraws = $lottery->drawsUnderCurrentRules($draws);
        if (\count($ruleDraws) < 50) {
            $ruleDraws = $draws;
        }
        $mainFreq = $statistics->mainFrequencies($draws);
        $bonusFreq = $statistics->bonusFrequencies($ruleDraws);
        $mainGaps = $statistics->mainGaps($draws);
        $bonusGaps = $statistics->bonusGaps($draws);
        arsort($mainGaps);
        arsort($bonusGaps);

        $hot = $mainFreq;
        arsort($hot);
        $cold = $mainFreq;
        asort($cold);
        $hotBonus = $bonusFreq;
        arsort($hotBonus);

        return $this->render('stats.html.twig', $common + [
            'enough' => true,
            'main' => $this->heatmap($mainFreq),
            'bonus' => $this->heatmap($bonusFreq),
            'ruleDrawCount' => \count($ruleDraws),
            'rulesSince' => \count($ruleDraws) < \count($draws) ? $game->currentRulesSince() : null,
            'hot' => \array_slice($hot, 0, 8, true),
            'cold' => \array_slice($cold, 0, 8, true),
            'hotBonus' => \array_slice($hotBonus, 0, 5, true),
            'maxMain' => max($mainFreq),
            'maxBonus' => max($bonusFreq),
            'mainGaps' => \array_slice($mainGaps, 0, 8, true),
            'bonusGaps' => \array_slice($bonusGaps, 0, 5, true),
            'maxGap' => max($mainGaps),
            'maxBonusGap' => max($bonusGaps),
            'mainAverageGap' => intdiv($game->mainPool(), $game->mainPicked()),
            'bonusAverageGap' => intdiv($game->bonusPool(), $game->bonusPicked()),
            'pairs' => $statistics->topPairs($draws, 8),
            'mainTest' => $statistics->uniformityTest($mainFreq),
            'bonusTest' => $statistics->uniformityTest($bonusFreq),
        ]);
    }

    private function dateQuery(Request $request, string $key): ?\DateTimeImmutable
    {
        $value = $request->query->getString($key);
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false === $date ? null : $date;
    }

    /**
     * @param non-empty-array<int,int> $frequencies
     *
     * @return list<array{value: int, count: int, t: float, expected: float}> t: 0 for the least drawn, 1 for the most drawn
     */
    private function heatmap(array $frequencies): array
    {
        $mean = array_sum($frequencies) / \count($frequencies);
        $min = min($frequencies);
        $range = max(1, max($frequencies) - $min);

        $cells = [];
        foreach ($frequencies as $value => $count) {
            $cells[] = ['value' => $value, 'count' => $count, 't' => ($count - $min) / $range, 'expected' => $mean];
        }

        return $cells;
    }
}
