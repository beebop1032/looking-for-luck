<?php

declare(strict_types=1);

namespace App\Web;

use App\Lottery\Lottery;
use App\Lottery\LotteryRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Turns the {lottery} route parameter into a Lottery, and remembers it on the
 * request so templates and formatting helpers know which game is on screen.
 */
final class LotteryValueResolver implements ValueResolverInterface
{
    public const ATTRIBUTE = '_current_lottery';

    public function __construct(private readonly LotteryRegistry $registry)
    {
    }

    /**
     * @return iterable<Lottery>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Lottery::class !== $argument->getType()) {
            return [];
        }

        $slug = $request->attributes->get('lottery');
        $lottery = \is_string($slug) ? $this->registry->find($slug) : null;
        if (null === $lottery) {
            throw new NotFoundHttpException(\sprintf('Unknown lottery "%s".', \is_string($slug) ? $slug : ''));
        }

        $request->attributes->set(self::ATTRIBUTE, $lottery);

        return [$lottery];
    }
}
