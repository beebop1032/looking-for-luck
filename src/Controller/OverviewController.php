<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\Lottery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OverviewController extends AbstractPageController
{
    #[Route('/{lottery}', name: 'overview', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['GET'])]
    public function index(Lottery $lottery): Response
    {
        $draws = $this->draws($lottery, (int) date('Y') - 1) ?? [];
        $latest = self::latest($draws);

        $grids = [];
        foreach ($lottery->grids()->all() as $saved) {
            $history = $lottery->grids()->history($saved, $draws, $lottery->prizeTable());
            $grids[] = [
                'saved' => $saved,
                'last' => $history[0] ?? null,
                'wins' => \count(array_filter($history, static fn (array $h): bool => null !== $h['rank'])),
            ];
        }

        return $this->render('overview.html.twig', [
            'lottery' => $lottery,
            'latest' => $latest,
            'nextJackpot' => $lottery->nextJackpot(),
            'recent' => array_reverse(\array_slice($draws, -7, 6)),
            'grids' => $grids,
        ]);
    }

    #[Route('/{lottery}/sync', name: 'sync', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['POST'])]
    public function sync(Lottery $lottery, Request $request): Response
    {
        $this->checkToken($request, 'sync');

        try {
            $result = $lottery->synchronizer()->sync();
            $this->flash('success', 'flash.synced', ['count' => $result['draws']]);
            foreach ($result['wins'] as $win) {
                $this->flash('win', 'flash.win', $win);
            }
        } catch (\RuntimeException $e) {
            $this->flash('error', 'flash.sync_failed', ['message' => $e->getMessage()]);
        }

        return $this->redirectToRoute('overview', ['lottery' => $lottery->slug()]);
    }
}
