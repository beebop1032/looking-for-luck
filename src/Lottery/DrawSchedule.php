<?php

declare(strict_types=1);

namespace App\Lottery;

/**
 * When a game's draws happen, computed in the game's own timezone.
 */
final class DrawSchedule
{
    private function __construct()
    {
    }

    /**
     * The first draw strictly after the given moment.
     */
    public static function nextAfter(LotteryGame $game, \DateTimeImmutable $moment): \DateTimeImmutable
    {
        $local = $moment->setTimezone(new \DateTimeZone($game->drawTimezone()));
        [$hour, $minute] = self::time($game);

        for ($day = 0; $day <= 7; ++$day) {
            $candidate = $local->modify(\sprintf('+%d days', $day))->setTime($hour, $minute);
            if ($candidate > $local && \in_array((int) $candidate->format('N'), $game->drawDays(), true)) {
                return $candidate;
            }
        }

        throw new \LogicException('No draw day found within a week.'); // unreachable: drawDays() is non-empty
    }

    /**
     * The last draw at or before the given moment.
     */
    public static function previousBefore(LotteryGame $game, \DateTimeImmutable $moment): \DateTimeImmutable
    {
        $local = $moment->setTimezone(new \DateTimeZone($game->drawTimezone()));
        [$hour, $minute] = self::time($game);

        for ($day = 0; $day <= 7; ++$day) {
            $candidate = $local->modify(\sprintf('-%d days', $day))->setTime($hour, $minute);
            if ($candidate <= $local && \in_array((int) $candidate->format('N'), $game->drawDays(), true)) {
                return $candidate;
            }
        }

        throw new \LogicException('No draw day found within a week.'); // unreachable
    }

    /**
     * @return array{int, int}
     */
    private static function time(LotteryGame $game): array
    {
        if (1 !== preg_match('/^(\d{2}):(\d{2})$/', $game->drawTime(), $m)) {
            throw new \LogicException(\sprintf('Invalid draw time "%s" for %s, expected HH:MM.', $game->drawTime(), $game->name()));
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
