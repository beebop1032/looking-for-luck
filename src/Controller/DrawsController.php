<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\Draw;
use App\Lottery\Grid;
use App\Lottery\Lottery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DrawsController extends AbstractPageController
{
    #[Route('/{lottery}/draws', name: 'draws', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['GET'])]
    public function index(Lottery $lottery, Request $request): Response
    {
        $firstYear = $lottery->draws()->firstYear();
        $currentYear = (int) date('Y');
        $year = $this->intQuery($request, 'year', $currentYear, $firstYear, $currentYear);

        $all = $this->draws($lottery) ?? [];

        $grid = null;
        $gridError = null;
        $replay = null;
        $rawGrid = trim($request->query->getString('grid'));
        if ('' !== $rawGrid) {
            try {
                $grid = $lottery->gridInput()->parse($rawGrid);
                $replay = $this->replay($lottery, $grid, $all);
            } catch (\InvalidArgumentException) {
                $gridError = true;
            }
        }

        $rows = [];
        foreach (array_reverse($all) as $draw) {
            if ((int) $draw->date->format('Y') !== $year) {
                continue;
            }
            $matches = null !== $grid ? $grid->matches($draw->grid) : null;
            $rows[] = [
                'draw' => $draw,
                'matches' => $matches,
                'rank' => null !== $matches ? $lottery->prizeTable()->rankFor($matches['main'], $matches['bonus']) : null,
            ];
        }

        return $this->render('draws.html.twig', [
            'lottery' => $lottery,
            'year' => $year,
            'years' => range($currentYear, $firstYear),
            'firstYear' => $firstYear,
            'rows' => $rows,
            'grid' => $grid,
            'rawGrid' => $rawGrid,
            'gridError' => $gridError,
            'replay' => $replay,
        ]);
    }

    /**
     * "What if I had played this grid at every draw since the beginning?".
     *
     * @param list<Draw> $draws
     *
     * @return array{draws: int, cost: float, winnings: float, byRank: array<int,int>, best: ?array{date: \DateTimeImmutable, rank: int}}
     */
    private function replay(Lottery $lottery, Grid $grid, array $draws): array
    {
        $table = $lottery->prizeTable();
        $byRank = [];
        $winnings = 0.0;
        $best = null;
        foreach ($draws as $draw) {
            $m = $grid->matches($draw->grid);
            $rank = $table->rankFor($m['main'], $m['bonus']);
            if (null === $rank) {
                continue;
            }
            $byRank[$rank] = ($byRank[$rank] ?? 0) + 1;
            $winnings += $table->prizeFor($rank);
            if (null === $best || $rank < $best['rank']) {
                $best = ['date' => $draw->date, 'rank' => $rank];
            }
        }
        ksort($byRank);

        return [
            'draws' => \count($draws),
            'cost' => \count($draws) * $lottery->game()->defaultTicketPrice(),
            'winnings' => $winnings,
            'byRank' => $byRank,
            'best' => $best,
        ];
    }
}
