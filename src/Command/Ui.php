<?php

declare(strict_types=1);

namespace App\Command;

use App\Intl\Format;
use App\Lottery\Grid;
use App\Web\GamblingNotice;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Console look & feel: lottery balls, gold stars, coloured money, bars and
 * heat colours. Everything returns console markup, so it degrades gracefully
 * to plain text when colours are disabled (--no-ansi, pipes, CI logs).
 */
final class Ui
{
    private const BLOCKS = ['', '▏', '▎', '▍', '▌', '▋', '▊', '▉'];

    private function __construct()
    {
    }

    public static function banner(OutputInterface $output, string $title, ?string $subtitle = null): void
    {
        $output->writeln('');
        $output->writeln(\sprintf(
            '  <fg=#2fbf71;options=bold>🍀 Looking for Luck</>  <fg=gray>│</>  <options=bold>%s</>',
            OutputFormatter::escape($title),
        ));
        if (null !== $subtitle) {
            $output->writeln('  <fg=gray>'.OutputFormatter::escape($subtitle).'</>');
        }
        $output->writeln('  <fg=gray>'.str_repeat('─', 72).'</>');
        self::warning($output);
        $output->writeln('');
    }

    /**
     * The responsible-gambling warning (see GamblingNotice), on every banner.
     */
    public static function warning(OutputInterface $output): void
    {
        $notice = GamblingNotice::forConsole(\Locale::getDefault());
        $output->writeln(\sprintf('  <fg=black;bg=#ffd23f;options=bold> %s </> <fg=#ffd23f>%s</>', $notice['age'], OutputFormatter::escape($notice['text'])));
        if (null !== $notice['help']) {
            $output->writeln('       <fg=#ffd23f>'.OutputFormatter::escape($notice['help']).'</>');
        }
    }

    /**
     * A grey explanatory paragraph, wrapped to the terminal (UTF-8 aware),
     * starting with an "ℹ" marker.
     */
    public static function note(OutputInterface $output, string $text, int $width = 88): void
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            if ('' !== $line && mb_strlen($line.' '.$word) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = '' === $line ? $word : $line.' '.$word;
            }
        }
        if ('' !== $line) {
            $lines[] = $line;
        }

        foreach ($lines as $i => $content) {
            $output->writeln(\sprintf('  <fg=gray>%s %s</>', 0 === $i ? 'ℹ ' : '  ', OutputFormatter::escape($content)));
        }
        $output->writeln('');
    }

    public static function ball(int $number, bool $highlight = false): string
    {
        return \sprintf(
            $highlight ? '<fg=black;bg=#2fbf71;options=bold> %02d </>' : '<fg=#1b1b1b;bg=#eeeeee;options=bold> %02d </>',
            $number,
        );
    }

    public static function star(int $number, bool $highlight = false): string
    {
        return \sprintf(
            $highlight ? '<fg=black;bg=#2fbf71;options=bold>★%02d </>' : '<fg=#3a2a00;bg=#ffc83d;options=bold>★%02d </>',
            $number,
        );
    }

    /**
     * @param list<int> $highlightMain
     */
    public static function balls(Grid $grid, array $highlightMain = []): string
    {
        return implode(' ', array_map(static fn (int $n): string => self::ball($n, \in_array($n, $highlightMain, true)), $grid->main));
    }

    /**
     * @param list<int> $highlightBonus
     */
    public static function stars(Grid $grid, array $highlightBonus = []): string
    {
        return implode(' ', array_map(static fn (int $n): string => self::star($n, \in_array($n, $highlightBonus, true)), $grid->bonus));
    }

    /**
     * A grid as balls and stars; numbers shared with $against are highlighted.
     */
    public static function grid(Grid $grid, ?Grid $against = null): string
    {
        $main = null !== $against ? array_values(array_intersect($grid->main, $against->main)) : [];
        $bonus = null !== $against ? array_values(array_intersect($grid->bonus, $against->bonus)) : [];

        return self::balls($grid, $main).'   '.self::stars($grid, $bonus);
    }

    /**
     * Money, green when positive, red when negative (signed when asked).
     */
    public static function money(float $amount, bool $signed = false): string
    {
        $text = ($signed && $amount > 0 ? '+' : '').Format::money($amount);

        return match (true) {
            $amount < 0 => '<fg=#ff5f5f>'.$text.'</>',
            $amount > 0 && $signed => '<fg=#2fbf71>'.$text.'</>',
            default => $text,
        };
    }

    /**
     * A probability for humans: a percentage when it is not tiny, "1 in N" otherwise.
     */
    public static function chance(float $probability): string
    {
        return match (true) {
            $probability > 0.999 => '> '.Format::decimal(99.9, 1).' %',
            $probability >= 0.01 => Format::decimal(100 * $probability, 1).' %',
            $probability > 0 => '1 / '.Format::integer(round(1 / $probability)),
            default => '0 %',
        };
    }

    /**
     * A horizontal bar with eighth-block precision.
     */
    public static function bar(float $ratio, int $width = 30, string $color = '#2fbf71'): string
    {
        $ratio = max(0.0, min(1.0, $ratio));
        $eighths = (int) round($ratio * $width * 8);
        $full = intdiv($eighths, 8);
        $bar = str_repeat('█', $full).self::BLOCKS[$eighths % 8];
        $pad = $width - $full - ('' === self::BLOCKS[$eighths % 8] ? 0 : 1);

        return \sprintf('<fg=%s>%s</>%s', $color, $bar, str_repeat(' ', max(0, $pad)));
    }

    /**
     * A position marker on a 0–100 scale, e.g. for a percentile.
     */
    public static function gauge(float $percent, int $width = 40): string
    {
        $position = (int) round(max(0.0, min(100.0, $percent)) / 100 * ($width - 1));

        return '<fg=gray>'.str_repeat('─', $position).'</><fg=#ffc83d;options=bold>●</><fg=gray>'.str_repeat('─', $width - 1 - $position).'</>';
    }

    /**
     * Cold-to-hot colour for a deviation in [-1, 1] (blue → grey → red).
     */
    public static function heat(float $deviation): string
    {
        $t = max(-1.0, min(1.0, $deviation));
        [$from, $to] = $t < 0 ? [[90, 90, 90], [52, 120, 246]] : [[90, 90, 90], [235, 64, 52]];
        $t = abs($t);

        return \sprintf(
            '#%02x%02x%02x',
            (int) round($from[0] + ($to[0] - $from[0]) * $t),
            (int) round($from[1] + ($to[1] - $from[1]) * $t),
            (int) round($from[2] + ($to[2] - $from[2]) * $t),
        );
    }
}
