<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\Lottery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class GenerateController extends AbstractPageController
{
    #[Route('/{lottery}/generate', name: 'generate', requirements: ['lottery' => '[a-z0-9-]+'], methods: ['GET'])]
    public function index(Lottery $lottery, Request $request): Response
    {
        $count = $this->intQuery($request, 'count', 5, 1, 20);
        $useFilter = '0' !== $request->query->get('filter');

        $filter = $useFilter ? $lottery->filter()->withHistory($this->draws($lottery) ?? []) : null;
        $result = $lottery->generator()->generate($count, $filter);
        arsort($result['rejected']);

        return $this->render('generate.html.twig', [
            'lottery' => $lottery,
            'count' => $count,
            'filter' => $useFilter,
            'grids' => $result['grids'],
            'rejected' => $result['rejected'],
            'rejectedGrids' => $result['rejectedGrids'],
            'rejectedTotal' => $result['rejectedTotal'],
        ]);
    }
}
