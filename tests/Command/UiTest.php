<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\Ui;
use App\Euromillions\EuromillionsGame;
use App\Lottery\Grid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Helper\Helper;

final class UiTest extends TestCase
{
    public function testBarKeepsAConstantWidth(): void
    {
        foreach ([0.0, 0.03, 0.5, 0.77, 1.0, 1.5, -1.0] as $ratio) {
            self::assertSame(20, Helper::width(Helper::removeDecoration(new \Symfony\Component\Console\Formatter\OutputFormatter(), Ui::bar($ratio, 20))), (string) $ratio);
        }
    }

    public function testGridHighlightsMatches(): void
    {
        $game = new EuromillionsGame();
        $grid = Grid::of($game, [1, 9, 23, 34, 48], [3, 7]);
        $draw = Grid::of($game, [1, 2, 3, 4, 48], [7, 12]);

        $rendered = Ui::grid($grid, $draw);

        self::assertSame(3, substr_count($rendered, 'bg=#2fbf71'), '2 numbers + 1 star highlighted');
        self::assertStringContainsString(' 23 ', $rendered);
        self::assertStringContainsString('★07', $rendered);
    }

    public function testBannerCarriesTheGamblingWarning(): void
    {
        // As if set in .env.local: Symfony's Dotenv fills $_SERVER, not getenv().
        $previous = $_SERVER['LFL_COUNTRY'] ?? null;
        $_SERVER['LFL_COUNTRY'] = 'fr';
        try {
            $output = new \Symfony\Component\Console\Output\BufferedOutput();
            Ui::banner($output, 'Title');
            $text = $output->fetch();
        } finally {
            if (null === $previous) {
                unset($_SERVER['LFL_COUNTRY']);
            } else {
                $_SERVER['LFL_COUNTRY'] = $previous;
            }
        }

        self::assertStringContainsString('18+', $text);
        self::assertStringContainsString('Les jeux d’argent et de hasard peuvent être dangereux', $text);
        self::assertStringNotContainsString('joueurs-info-service.fr', $text);
    }

    public function testMoneyIsColouredBySign(): void
    {
        \Locale::setDefault('en');
        self::assertStringContainsString('#ff5f5f', Ui::money(-3.5));
        self::assertStringContainsString('+€1,000.00', Ui::money(1000, true));
        self::assertSame('€0.00', Ui::money(0, true));
    }

    public function testFormattingFollowsTheOutputLanguage(): void
    {
        \Locale::setDefault('fr');
        self::assertSame('1 234,50 €', str_replace(["\u{202F}", "\u{00A0}"], ' ', \App\Intl\Format::money(1234.5)));
        self::assertSame('> 99,9 %', Ui::chance(0.9999));
        \Locale::setDefault('en');
        self::assertSame('1 / 134,460', Ui::chance(1 / 134_460));
    }

    public function testHeatGoesFromBlueToRed(): void
    {
        self::assertSame('#3478f6', Ui::heat(-1));
        self::assertSame('#5a5a5a', Ui::heat(0));
        self::assertSame('#eb4034', Ui::heat(1));
    }
}
