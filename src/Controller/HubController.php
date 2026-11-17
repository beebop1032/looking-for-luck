<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\Lottery;
use App\Lottery\LotteryRegistry;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HubController extends AbstractPageController
{
    public function __construct(
        TranslatorInterface $translator,
        private readonly CacheInterface $cache,
    ) {
        parent::__construct($translator);
    }

    #[Route('/', name: 'hub', methods: ['GET'])]
    public function index(LotteryRegistry $registry): Response
    {
        $lotteries = [];
        foreach ($registry->all() as $lottery) {
            $lotteries[] = $this->showcase($lottery);
        }

        return $this->render('hub.html.twig', [
            'lotteries' => $lotteries,
            'planned' => $registry->planned(),
        ]);
    }

    /**
     * A live preview of every tool, with this lottery's real data, so the
     * home page shows what the app does instead of describing it.
     *
     * @return array<string, mixed>
     */
    private function showcase(Lottery $lottery): array
    {
        $game = $lottery->game();
        $draws = $this->draws($lottery) ?? [];
        $item = ['lottery' => $lottery, 'latest' => self::latest($draws), 'nextJackpot' => $lottery->nextJackpot()];
        if ([] === $draws) {
            return $item;
        }

        // Generate: a grid drawn right now, through the anti-sharing filter.
        $item['grid'] = $lottery->generator()->generate(1, $lottery->filter()->withHistory($draws))['grids'][0];

        // Statistics: the most drawn numbers under the current rules, and the χ² verdict.
        $ruleDraws = $lottery->drawsUnderCurrentRules($draws);
        $statistics = $lottery->statistics();
        $frequencies = $statistics->mainFrequencies($ruleDraws);
        arsort($frequencies);
        $item['hot'] = \array_slice(array_keys($frequencies), 0, 5);
        $item['random'] = $statistics->uniformityTest($statistics->mainFrequencies($draws))['compatible'];

        // Draw history.
        $item['drawCount'] = \count($draws);
        $item['firstYear'] = $lottery->draws()->firstYear();

        // My grids.
        $item['savedGrids'] = \count($lottery->grids()->all());

        // Budget: one grid at every draw for ten years, with the same jackpot
        // as the Budget page: what is staked, what comes back, what is lost.
        $grids = 10 * 52 * $game->drawsPerWeek();
        $table = $lottery->prizeTable()->withJackpot($item['nextJackpot']->amount ?? $game->defaultJackpot());
        $chances = $lottery->budget()->chances($grids, $table);
        // The typical amount won back only depends on the jackpot and the
        // price: simulated once a day, not on every visit.
        $price = $game->defaultTicketPrice();
        $cost = $grids * $price;
        $key = \sprintf('hub.won_back.%s.%d.%d', $lottery->slug(), (int) $table->prizeFor(1), (int) round(100 * $price));
        $back = $this->cache->get($key, static function (ItemInterface $item) use ($lottery, $price, $table): float {
            $item->expiresAfter(86_400);

            return $lottery->budget()->simulate(10, 1, $price, 5_000, $table)['wonBack']['median'];
        });
        $item['budget'] = [
            'cost' => $cost,
            'back' => $back,
            'lost' => $cost - $back,
            'any' => $chances['any']['atLeastOnce'],
            'jackpot' => $chances['tiers'][0]['atLeastOnce'],
        ];

        // Group play: ten friends, one simple grid each.
        $group = $lottery->groupPlay()->group(10, $game->mainPicked(), $game->bonusPicked(), $game->defaultTicketPrice());
        $item['group'] = [
            'people' => 10,
            'grids' => $group['combinations'],
            'jackpot' => $group['jackpot'],
            'single' => $lottery->prizeTable()->probability($game->mainPicked(), $game->bonusPicked()),
            'perPerson' => $group['cost'] / 10,
        ];

        // Strategy lab: how many draws a strategy is replayed on.
        $item['labDraws'] = \count($ruleDraws);

        return $item;
    }
}
