<?php

declare(strict_types=1);

namespace App\Controller;

use App\Lottery\Draw;
use App\Lottery\Lottery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

abstract class AbstractPageController extends AbstractController
{
    public function __construct(protected readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function flash(string $type, string $key, array $parameters = []): void
    {
        $this->addFlash($type, $this->translator->trans($key, $parameters));
    }

    /**
     * The draw history, or null (with a flash message) when it is unavailable.
     *
     * @return list<Draw>|null
     */
    protected function draws(Lottery $lottery, ?int $sinceYear = null): ?array
    {
        try {
            return $lottery->draws()->all($sinceYear);
        } catch (\RuntimeException $e) {
            $this->flash('error', 'flash.history_unavailable', ['message' => $e->getMessage()]);

            return null;
        }
    }

    protected function checkToken(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
    }

    protected function intQuery(Request $request, string $key, int $default, int $min, int $max): int
    {
        $value = $request->query->get($key);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    protected function floatQuery(Request $request, string $key, float $default, float $min, float $max): float
    {
        $value = $request->query->get($key);

        return is_numeric($value) ? max($min, min($max, (float) $value)) : $default;
    }

    /**
     * @param list<Draw> $draws
     */
    protected static function latest(array $draws): ?Draw
    {
        return [] === $draws ? null : $draws[array_key_last($draws)];
    }
}
