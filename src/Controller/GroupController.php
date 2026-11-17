<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\GroupPlay;
use App\Lottery\Lottery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GroupController extends AbstractPageController
{
    /** Combinations above this are not playable in practice (and get expensive fast). */
    private const MAX_COMBINATIONS = 5_000;
    private const MAX_TICKETS = 1_000;
    /** Tickets listed on the page (all of them up to this number). */
    public const SHOWN_TICKETS = 100;

    #[Route('/{lottery}/group', name: 'group', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['GET'])]
    public function index(Lottery $lottery, Request $request): Response
    {
        $game = $lottery->game();
        // The same limits as a multiple grid typed in My grids, so every ticket can be saved.
        $maxMain = $lottery->gridInput()->maxMain();
        $maxBonus = $lottery->gridInput()->maxBonus();

        $params = [
            'people' => $this->intQuery($request, 'people', 10, 1, 100),
            // A simple grid by default; more numbers make it a multiple grid.
            'numbers' => $this->intQuery($request, 'numbers', $game->mainPicked(), $game->mainPicked(), $maxMain),
            'bonus' => $this->intQuery($request, 'bonus', $game->bonusPicked(), $game->bonusPicked(), $maxBonus),
            'price' => $this->floatQuery($request, 'price', $game->defaultTicketPrice(), 0.5, 50),
            'years' => $this->intQuery($request, 'years', 1, 1, 30),
        ];

        $group = $lottery->groupPlay();
        $perTicket = $group->analyse($params['numbers'], $params['bonus'], $params['price'])['combinations'];

        // By default, one grid per person (fewer tickets when a multiple grid covers several people).
        $params['tickets'] = $this->intQuery($request, 'tickets', (int) ceil($params['people'] / $perTicket), 1, self::MAX_TICKETS);

        $result = $group->group($params['tickets'], $params['numbers'], $params['bonus'], $params['price']);
        $tooBig = $result['combinations'] > self::MAX_COMBINATIONS;

        $draws = $params['years'] * 52 * $game->drawsPerWeek();
        $perPerson = $result['cost'] / $params['people'];
        $single = $lottery->prizeTable()->probability($game->mainPicked(), $game->bonusPicked());
        // Alone, with the same money as one share: how many simple grids, and which jackpot odds.
        $aloneGrids = max(1, (int) floor($perPerson / $params['price']));
        $jackpot = $lottery->nextJackpot()->amount ?? $lottery->prizeTable()->prizeFor(1);

        $examples = [];
        for ($i = 0; $i < min(self::SHOWN_TICKETS, $params['tickets']); ++$i) {
            $examples[] = $lottery->generator()->multiple($params['numbers'], $params['bonus']);
        }

        return $this->render('group.html.twig', [
            'lottery' => $lottery,
            'params' => $params,
            'maxMain' => $maxMain,
            'maxBonus' => $maxBonus,
            'result' => $result,
            'tooBig' => $tooBig,
            'examples' => $examples,
            'perPerson' => $perPerson,
            'draws' => $draws,
            'overPeriod' => [
                'any' => GroupPlay::overDraws($result['any'], $draws),
                'jackpot' => GroupPlay::overDraws($result['jackpot'], $draws),
            ],
            'alone' => ['grids' => $aloneGrids, 'jackpot' => min(1.0, $aloneGrids * $single)],
            'share' => $jackpot / $params['people'],
            'jackpotAmount' => $jackpot,
        ]);
    }
}
