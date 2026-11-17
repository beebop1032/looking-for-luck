<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Web\GamblingNotice;
use PHPUnit\Framework\TestCase;

final class GamblingNoticeTest extends TestCase
{
    public function testFranceQuotesTheArreteVerbatimWhateverTheInterfaceLanguage(): void
    {
        foreach (['fr', 'en'] as $locale) {
            $notice = GamblingNotice::for($locale, ['fr_FR', 'fr']);
            self::assertSame('fr', $notice['country']);
            self::assertSame('fr', $notice['lang']);
            self::assertSame('Les jeux d’argent et de hasard peuvent être dangereux : pertes d’argent, conflits familiaux, addiction…', $notice['text']);
            self::assertNull($notice['help']);
        }
    }

    public function testBelgiumFollowsTheLanguage(): void
    {
        self::assertSame('Le jeu peut rendre dépendant. Arrêtez-vous à temps!', GamblingNotice::for('fr', ['fr_BE'])['text']);
        self::assertSame('Gokken kan verslavend zijn. Stop op tijd!', GamblingNotice::for('nl', ['nl_BE'])['text']);
        self::assertSame('fr', GamblingNotice::for('de', ['de_BE'])['lang']);
    }

    public function testConfiguredCountryWins(): void
    {
        self::assertSame('be', GamblingNotice::for('fr', ['fr_FR'], 'BE')['country']);
    }

    public function testElsewhereAPlainWarningInTheInterfaceLanguage(): void
    {
        $notice = GamblingNotice::for('es', ['es_ES', 'es']);
        self::assertSame('es', $notice['country']);
        self::assertSame('es', $notice['lang']);
        self::assertStringContainsString('adicción', $notice['text']);
        self::assertSame('18+', $notice['age']);
        self::assertSame('en', GamblingNotice::for('it', [])['lang']);
    }

    public function testRegionIsReadFromPosixLocales(): void
    {
        self::assertSame('be', GamblingNotice::for('fr', ['fr_BE.UTF-8'])['country']);
        self::assertNull(GamblingNotice::for('fr', ['fr', 'C.UTF-8'])['country']);
    }
}
