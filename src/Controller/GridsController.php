<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\DuplicateGridException;
use App\Lottery\GridStorageFullException;
use App\Lottery\Lottery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/{lottery}/grids', requirements: ['lottery' => '[a-z0-9-]+'])]
final class GridsController extends AbstractPageController
{
    /** At most this many grids saved by one request (all the tickets of a group). */
    public const MAX_BATCH = GroupController::SHOWN_TICKETS;

    #[Route('', name: 'grids', methods: ['GET'])]
    public function index(Lottery $lottery): Response
    {
        $draws = $this->draws($lottery) ?? [];
        $table = $lottery->prizeTable();

        $grids = [];
        $game = $lottery->game();
        foreach ($lottery->grids()->all() as $saved) {
            $history = $lottery->grids()->history($saved, $draws, $table);
            $combinations = $saved->grid->combinations($game);
            $grids[] = [
                'saved' => $saved,
                'history' => \array_slice($history, 0, 10),
                'played' => \count($history),
                'wins' => \count(array_filter($history, static fn (array $h): bool => null !== $h['rank'])),
                'winnings' => array_sum(array_column($history, 'amount')),
                'cost' => \count($history) * $combinations * $game->defaultTicketPrice(),
                'combinations' => $combinations,
                'multiple' => $saved->grid->isMultiple($game),
                'pricePerDraw' => $combinations * $game->defaultTicketPrice(),
            ];
        }

        return $this->render('grids.html.twig', ['lottery' => $lottery, 'grids' => $grids]);
    }

    #[Route('', name: 'grids_add', methods: ['POST'])]
    public function add(Lottery $lottery, Request $request): Response
    {
        $this->checkToken($request, 'grids');

        // Several grids at once (e.g. every ticket of a group).
        $many = $request->request->all('grids');
        if ([] !== $many) {
            $grids = [];
            foreach (\array_slice($many, 0, self::MAX_BATCH) as $value) {
                try {
                    $grids[] = $lottery->gridInput()->parse(\is_string($value) ? $value : '', true);
                } catch (\InvalidArgumentException) {
                    // Invalid entries are skipped, like duplicates.
                }
            }
            try {
                $added = $lottery->grids()->addMany($grids);
                $this->flash('success', 'flash.grids_saved', ['count' => \count($added)]);
            } catch (GridStorageFullException) {
                $this->flash('error', 'flash.grids_full');
            }

            return $this->redirectToRoute('grids', ['lottery' => $lottery->slug()]);
        }

        try {
            $grid = $lottery->gridInput()->parse($request->request->getString('grid'), true);
            $saved = $lottery->grids()->add($grid->main, $grid->bonus);
            $this->flash('success', 'flash.grid_saved', ['id' => $saved->id, 'grid' => $grid->format()]);
        } catch (DuplicateGridException $e) {
            $this->flash('error', 'flash.grid_duplicate', ['id' => $e->existingId]);
        } catch (GridStorageFullException) {
            $this->flash('error', 'flash.grids_full');
        } catch (\InvalidArgumentException) {
            $game = $lottery->game();
            $this->flash('error', 'flash.grid_invalid_multiple', [
                'main' => $game->mainPicked(),
                'mainMax' => $lottery->gridInput()->maxMain(),
                'mainPool' => $game->mainPool(),
                'bonus' => $game->bonusPicked(),
                'bonusMax' => $lottery->gridInput()->maxBonus(),
                'bonusPool' => $game->bonusPool(),
                'example' => $lottery->gridInput()->example(),
            ]);
        }

        return $this->redirectToRoute('grids', ['lottery' => $lottery->slug()]);
    }

    #[Route('/{id<\d+>}/delete', name: 'grids_delete', methods: ['POST'])]
    public function delete(Lottery $lottery, int $id, Request $request): Response
    {
        $this->checkToken($request, 'grids');

        $lottery->grids()->remove($id)
            ? $this->flash('success', 'flash.grid_deleted', ['id' => $id])
            : $this->flash('error', 'flash.grid_missing', ['id' => $id]);

        return $this->redirectToRoute('grids', ['lottery' => $lottery->slug()]);
    }
}
