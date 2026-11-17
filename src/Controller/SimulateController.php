<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\Lottery;
use App\Lottery\StrategyLab;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SimulateController extends AbstractPageController
{
    #[Route('/{lottery}/budget', name: 'budget', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['GET'])]
    public function budget(Lottery $lottery, Request $request): Response
    {
        $game = $lottery->game();
        // By default, simulate with the jackpot announced for the next draw.
        $defaultJackpot = $lottery->nextJackpot()->amount ?? $game->defaultJackpot();
        $params = [
            'years' => $this->intQuery($request, 'years', 10, 1, 80),
            'price' => $this->floatQuery($request, 'price', $game->defaultTicketPrice(), 0.5, 50),
            'grids' => $this->intQuery($request, 'grids', 1, 1, 100),
            'jackpot' => $this->floatQuery($request, 'jackpot', $defaultJackpot, $game->minimumJackpot(), $game->maximumJackpot()),
            'simulations' => $this->intQuery($request, 'simulations', 10_000, 1_000, 20_000),
        ];

        $table = $lottery->prizeTable()->withJackpot($params['jackpot']);
        $simulator = $lottery->budget();
        $result = $simulator->simulate($params['years'], $params['grids'], $params['price'], $params['simulations'], $table);
        $perWeek = $params['grids'] * $game->drawsPerWeek() * $params['price'];

        return $this->render('budget.html.twig', [
            'lottery' => $lottery,
            'params' => $params,
            'maxJackpot' => $game->maximumJackpot(),
            'result' => $result,
            'chances' => $simulator->chances($result['gridsPlayed'], $table),
            'spend' => ['week' => $perWeek, 'year' => $perWeek * 52, 'total' => $result['totalCost']],
            'back' => $result['wonBack'],
        ]);
    }

    #[Route('/{lottery}/lab', name: 'lab', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['GET'])]
    public function lab(Lottery $lottery, Request $request): Response
    {
        $strategy = $request->query->getString('strategy', 'hot');
        if (!\in_array($strategy, StrategyLab::STRATEGIES, true)) {
            $strategy = 'hot';
        }
        $params = [
            'strategy' => $strategy,
            'players' => $this->intQuery($request, 'players', 10_000, 100, 50_000),
            'price' => $this->floatQuery($request, 'price', $lottery->game()->defaultTicketPrice(), 0.5, 50),
        ];

        $result = null;
        $draws = $this->draws($lottery) ?? [];
        if ([] !== $draws) {
            try {
                $result = $lottery->lab()->run($strategy, $draws, $params['players'], $params['price']);
            } catch (\InvalidArgumentException) {
                $this->flash('error', 'flash.not_enough_history');
            }
        }

        // The most recent plays, newest first, or only the winning ones.
        $showWins = 'wins' === $request->query->get('plays');
        $plays = [];
        if (null !== $result) {
            $plays = array_reverse($result['plays']);
            if ($showWins) {
                $plays = array_values(array_filter($plays, static fn (array $p): bool => null !== $p['rank']));
            }
            $plays = \array_slice($plays, 0, $showWins ? 50 : 12);
        }

        return $this->render('lab.html.twig', [
            'plays' => $plays,
            'showWins' => $showWins,
            'lottery' => $lottery,
            'params' => $params,
            'strategies' => StrategyLab::STRATEGIES,
            'result' => $result,
            'rulesSince' => $lottery->game()->currentRulesSince(),
            'firstYear' => $lottery->draws()->firstYear(),
        ]);
    }
}
