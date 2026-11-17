<?php

declare(strict_types=1);

namespace App\Tests\Web;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests of the web interface against a synthetic, offline draw history
 * (written to the test data dir, so no request ever reaches the network).
 */
final class PagesTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $dataDir;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $dataRoot = self::getContainer()->getParameter('app.data_root');
        self::assertIsString($dataRoot);
        $this->dataDir = $dataRoot.'/euromillions';

        $this->removeDataDir();
        mkdir($this->dataDir, 0775, true);
        $this->writeFixtures();
    }

    protected function tearDown(): void
    {
        $this->removeDataDir();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pageProvider(): iterable
    {
        yield 'home page' => ['/'];
        yield 'overview' => ['/euromillions'];
        yield 'generate' => ['/euromillions/generate?count=3'];
        yield 'generate without filter' => ['/euromillions/generate?filter=0'];
        yield 'draws' => ['/euromillions/draws?year=2004'];
        yield 'draws with a grid' => ['/euromillions/draws?year=2004&grid=1+2+3+4+5+%2B+1+2'];
        yield 'draws with an invalid grid' => ['/euromillions/draws?grid=nope'];
        yield 'stats' => ['/euromillions/stats'];
        yield 'stats with a date range' => ['/euromillions/stats?from=2004-03-01&to=2004-10-31'];
        yield 'stats with nonsense dates' => ['/euromillions/stats?from=yesterday&to=2999-01-01'];
        yield 'grids' => ['/euromillions/grids'];
        yield 'budget' => ['/euromillions/budget?years=2&simulations=1000'];
        yield 'lab' => ['/euromillions/lab?strategy=cold&players=1000'];
        yield 'lab, random strategy, winning plays' => ['/euromillions/lab?strategy=random&players=1000&plays=wins'];
        yield 'group play' => ['/euromillions/group'];
        yield 'group play, big grid' => ['/euromillions/group?people=3&numbers=10&bonus=12&years=5'];
        yield 'group play, nonsense' => ['/euromillions/group?numbers=99&bonus=-1&people=0'];
        yield 'health' => ['/health'];
    }

    #[DataProvider('pageProvider')]
    public function testPageRenders(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful();
    }

    public function testUnknownLotteryIsNotFound(): void
    {
        $this->client->request('GET', '/powerball/stats');

        self::assertResponseStatusCodeSame(404);
    }

    public function testHomePageListsSupportedAndPlannedLotteries(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorExists('a[href="/euromillions"]');
        // Planned lotteries link to their GitHub issue.
        self::assertGreaterThanOrEqual(3, $crawler->filter('a[href*="/issues/"]')->count());
        self::assertSelectorTextContains('.lottery.is-planned', 'Eurojackpot');
        // Every tool of a supported lottery is shown with a live preview, linking to its page.
        self::assertCount(7, $crawler->filter('.showcase .feature'));
        foreach (['generate', 'group', 'stats', 'budget', 'draws', 'lab', 'grids'] as $page) {
            self::assertCount(1, $crawler->filter(\sprintf('.showcase a.feature[href="/euromillions/%s"]', $page)), $page);
        }
        self::assertCount(7, $crawler->filter('.showcase .feature .balls--lg > .ball, .showcase .feature .balls--lg > .star'), 'a freshly generated grid');

        // Visitors first: the hero leads to the budget, not to GitHub.
        self::assertSelectorExists('.stage__actions a[href="/euromillions/budget"]');
        self::assertSelectorNotExists('.stage__actions a[href*="github.com"]');
        // The three everyday uses come first, the tools for the curious after.
        self::assertCount(3, $crawler->filter('.showcase .features:not(.features--small) > .feature'));
        self::assertCount(4, $crawler->filter('.showcase .features--small > .feature'));
        // The cost is shown as money staked, got back and lost, and the lost amount is the difference.
        $amounts = $crawler->filter('.feature--budget .figure dd')->each(static fn ($dd): int => (int) preg_replace('/\D/', '', $dd->text()));
        self::assertCount(3, $amounts);
        self::assertSame(2600, $amounts[0]);
        self::assertEqualsWithDelta($amounts[0] - $amounts[1], $amounts[2], 1);
        self::assertSelectorTextContains('.feature--budget', 'do not pay back the stake');
        // A chance never wraps over two lines.
        self::assertStringContainsString("(>\u{a0}99.9\u{a0}%)", $crawler->filter('.feature--budget .feature__text')->text(null, false));
    }

    public function testRandomnessIsNeverClaimedAsProven(): void
    {
        $this->client->request('GET', '/?lang=fr');
        self::assertStringNotContainsString('le confirme', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', '/euromillions/grids');
        self::assertSelectorTextContains('main', 'Où sont conservées mes grilles');
    }

    public function testGroupPlayShowsTheMultipleGridAndTheHonestAnswer(): void
    {
        $crawler = $this->client->request('GET', '/euromillions/group?people=10&numbers=7&bonus=3');

        // 7 numbers + 3 stars = C(7,5) × C(3,2) = 63 combinations: one ticket covers the 10 people.
        self::assertSelectorTextContains('.figures', '63');
        self::assertCount(10, $crawler->filter('.list .balls--lg')->first()->filter('.ball, .star'));
        self::assertSelectorTextContains('.callout', 'does not beat the odds');
    }

    public function testDrawsPageHighlightsATestedGrid(): void
    {
        $crawler = $this->client->request('GET', '/euromillions/draws?year=2004&grid=1+2+3+4+5+%2B+1+2');

        self::assertSelectorTextContains('h2', 'Played at every draw since 2004');
        self::assertGreaterThan(0, $crawler->filter('.balls--compare .ball.hit')->count());
    }

    public function testOverviewShowsTheLatestDrawAndACountdown(): void
    {
        $crawler = $this->client->request('GET', '/euromillions');

        // 5 balls coloured by tens + 2 stars land on the stage.
        self::assertCount(5, $crawler->filter('.stage .balls--xl .ball'));
        self::assertCount(2, $crawler->filter('.stage .balls--xl .star'));
        self::assertSame(1, preg_match('/^d[0-5]$/', explode(' ', (string) $crawler->filter('.stage .ball')->attr('class'))[1]));
        // The live countdown targets the next draw.
        $next = $crawler->filter('[data-countdown]')->attr('data-countdown');
        self::assertNotNull($next);
        self::assertGreaterThan(time(), strtotime($next));
    }

    public function testStatisticsFollowTheExactDateRange(): void
    {
        // Fixtures: draws every 3 days from 2 January 2004.
        $crawler = $this->client->request('GET', '/euromillions/stats?from=2004-03-01&to=2004-10-31');

        self::assertSelectorTextContains('.stage__lead', 'March 2, 2004');
        self::assertSame('2004-03-01', $crawler->filter('input[name="from"]')->attr('value'));
        self::assertSame('2004-10-31', $crawler->filter('input[name="to"]')->attr('value'));
    }

    public function testBudgetShowsSpendingAndCumulativeChances(): void
    {
        $crawler = $this->client->request('GET', '/euromillions/budget?years=10&grids=1&price=2.5&simulations=1000&jackpot=5000000000');

        // 10 years × 52 weeks × 2 draws × 2.50 €.
        self::assertSelectorTextContains('.figures', '€2,600');
        self::assertCount(13, $crawler->filter('table tbody tr'));
        // The jackpot is capped at the EuroMillions maximum.
        self::assertSame('250000000', $crawler->filter('form.controls input[name="jackpot"]')->attr('value'));
    }

    public function testSaveAndDeleteAGrid(): void
    {
        $crawler = $this->client->request('GET', '/euromillions/grids');
        $form = $crawler->filter('form[method="post"][action="/euromillions/grids"]')->form(['grid' => '7 14 21 28 35 + 4 9']);
        $this->client->submit($form);
        self::assertResponseRedirects('/euromillions/grids');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'Grid 1 saved');
        self::assertCount(1, $crawler->filter('form[action="/euromillions/grids/1/delete"]'));

        $this->client->submit($crawler->filter('form[action="/euromillions/grids/1/delete"]')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'Grid 1 deleted');
    }

    public function testSaveAMultipleGrid(): void
    {
        $crawler = $this->client->request('GET', '/euromillions/grids');
        $this->client->submit($crawler->filter('form[method="post"][action="/euromillions/grids"]')->form(['grid' => '1 9 23 34 48 50 + 3 7 11']));
        $crawler = $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--success', 'Grid 1 saved');
        // 6 numbers + 3 stars = C(6,5) × C(3,2) = 18 combinations.
        self::assertSelectorTextContains('.tag--gold', '18 combinations');
    }

    public function testInBrowserModeEachVisitorKeepsTheirOwnGrids(): void
    {
        $_SERVER['LFL_GRIDS'] = 'browser';
        try {
            $crawler = $this->client->request('GET', '/euromillions/grids');
            self::assertSelectorTextContains('main', 'In this browser only');
            $this->client->submit($crawler->filter('form[method="post"][action="/euromillions/grids"]')->form(['grid' => '1 9 23 34 48 50 + 3 7 11']));

            // Kept in a cookie of this browser, never on the server.
            $cookie = $this->client->getCookieJar()->get('lfl_grids_euromillions');
            self::assertNotNull($cookie);
            self::assertTrue($cookie->isHttpOnly());
            self::assertSame('1.1-9-23-34-48-50.3-7-11.'.date('Ymd'), $cookie->getValue());
            self::assertFileDoesNotExist($this->dataDir.'/my-grids.json');

            $this->client->followRedirect();
            self::assertSelectorTextContains('.flash--success', 'Grid 1 saved');
            self::assertSelectorTextContains('.tag--gold', '18 combinations');
            $this->client->request('GET', '/');
            self::assertSelectorTextContains('a.feature[href="/euromillions/grids"]', '1');

            // Deleting the last grid removes the cookie.
            $crawler = $this->client->request('GET', '/euromillions/grids');
            $this->client->submit($crawler->filter('form[action="/euromillions/grids/1/delete"]')->form());
            self::assertNull($this->client->getCookieJar()->get('lfl_grids_euromillions'));

            // Another visitor (no cookie) sees nothing.
            $this->client->getCookieJar()->clear();
            $this->client->request('GET', '/euromillions/grids');
            self::assertSelectorExists('.empty');

            // A forged cookie is validated like a hand-edited file: invalid grids are dropped.
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('lfl_grids_euromillions', '1.1-2-3-4-99.1-2.20260101_2.5-6-7-8-9.1-2.20260101_<script>'));
            $this->client->request('GET', '/euromillions/grids');
            self::assertCount(1, $this->client->getCrawler()->filter('main .block--wide .list__index'));
        } finally {
            unset($_SERVER['LFL_GRIDS']);
        }
    }

    public function testInBrowserModeAFullCookieIsReported(): void
    {
        $_SERVER['LFL_GRIDS'] = 'browser';
        try {
            // Nearly 4 KB of the largest multiple grids already saved.
            $big = implode('-', range(1, 10)).'.'.implode('-', range(1, 12)).'.20260101';
            $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('lfl_grids_euromillions', implode('_', array_map(static fn (int $i): string => $i.'.'.$big, range(1, 70)))));

            $crawler = $this->client->request('GET', '/euromillions/grids');
            $this->client->submit($crawler->filter('form[method="post"][action="/euromillions/grids"]')->form(['grid' => '1 9 23 34 48 + 3 7']));
            $this->client->followRedirect();

            self::assertSelectorTextContains('.flash--error', 'No more room in this browser');
        } finally {
            unset($_SERVER['LFL_GRIDS']);
        }
    }

    public function testGroupListsEveryTicketAndSavesThemAll(): void
    {
        // Default: one simple grid (5 numbers + 2 stars) per person, all listed.
        $crawler = $this->client->request('GET', '/euromillions/group?people=10');
        self::assertSame('10', $crawler->filter('form.controls input[name="tickets"]')->attr('value'));
        self::assertCount(10, $crawler->filter('.block .list > li'));

        // The number of tickets is free: no minimum is imposed.
        $crawler = $this->client->request('GET', '/euromillions/group?people=10&tickets=2');
        self::assertSame('2', $crawler->filter('form.controls input[name="tickets"]')->attr('value'));
        self::assertCount(2, $crawler->filter('.block .list > li'));

        // Save both tickets at once.
        $this->client->submit($crawler->filter('form:has(input[name="grids[]"])')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash--success', '2 grids saved');
        self::assertCount(2, $crawler->filter('form[action$="/delete"]'));
    }

    public function testInvalidGridShowsAnError(): void
    {
        $crawler = $this->client->request('GET', '/euromillions/grids');
        $this->client->submit($crawler->filter('form[method="post"][action="/euromillions/grids"]')->form(['grid' => '1 2 3']));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash--error', 'Type 5 to 10 different numbers from 1 to 50');
    }

    public function testDuplicateGridIsRejected(): void
    {
        foreach ([1, 2] as $attempt) {
            $crawler = $this->client->request('GET', '/euromillions/grids');
            $this->client->submit($crawler->filter('form[method="post"][action="/euromillions/grids"]')->form(['grid' => '7 14 21 28 35 + 4 9']));
            $this->client->followRedirect();
        }

        self::assertSelectorTextContains('.flash--error', 'already saved as grid 1');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function languageProvider(): iterable
    {
        yield 'en' => ['en', 'Statistics'];
        yield 'fr' => ['fr', 'Statistiques'];
        yield 'nl' => ['nl', 'Statistieken'];
        yield 'es' => ['es', 'Estadísticas'];
        yield 'de' => ['de', 'Statistik'];
    }

    #[DataProvider('languageProvider')]
    public function testEveryPageIsTranslated(string $locale, string $statsTitle): void
    {
        foreach (['/', '/euromillions', '/euromillions/generate', '/euromillions/draws', '/euromillions/grids', '/euromillions/budget?simulations=1000', '/euromillions/lab?players=1000'] as $url) {
            $this->client->request('GET', $url.(str_contains($url, '?') ? '&' : '?').'lang='.$locale);
            self::assertResponseIsSuccessful($url);
            self::assertSelectorExists(\sprintf('html[lang="%s"]', $locale));
        }

        $this->client->request('GET', '/euromillions/stats?lang='.$locale);
        self::assertSelectorTextContains('h1', $statsTitle);
    }

    public function testNoAnalyticsUnlessTheDeploymentAsksForIt(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorNotExists('[data-consent]');
        self::assertSelectorNotExists('[data-consent-open]');
        self::assertStringNotContainsString('googletagmanager', (string) $this->client->getResponse()->getContent());
    }

    public function testAnalyticsWaitForConsent(): void
    {
        $_SERVER['LFL_GA4_ID'] = 'G-TEST1234';
        try {
            $this->client->request('GET', '/euromillions/stats?lang=fr');
        } finally {
            unset($_SERVER['LFL_GA4_ID']);
        }
        $html = (string) $this->client->getResponse()->getContent();

        // A banner (hidden until the script shows it), and a footer link to change one's mind.
        self::assertSelectorExists('[data-consent][hidden] [data-consent-choice="granted"]');
        self::assertSelectorExists('[data-consent-choice="denied"]');
        self::assertSelectorTextContains('[data-consent]', 'Google Analytics');
        self::assertSelectorExists('.footer [data-consent-open]');
        self::assertStringContainsString('"G-TEST1234"', $html);
        // The tag is only injected by the script, never as a static <script src>.
        self::assertSelectorNotExists('script[src*="googletagmanager"]');
    }

    public function testAMalformedAnalyticsIdIsIgnored(): void
    {
        $_SERVER['LFL_GA4_ID'] = 'G-1"</script><script>alert(1)';
        try {
            $this->client->request('GET', '/');
        } finally {
            unset($_SERVER['LFL_GA4_ID']);
        }

        self::assertSelectorNotExists('[data-consent]');
    }

    public function testEveryPageCarriesTheGamblingWarning(): void
    {
        $this->client->request('GET', '/', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'fr-FR,fr;q=0.9']);
        self::assertSelectorTextContains('.warning', '18+');
        self::assertSelectorTextContains('.warning', 'Les jeux d’argent et de hasard peuvent être dangereux');
        self::assertSelectorNotExists('.warning a');

        $this->client->request('GET', '/euromillions/budget', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'nl-BE,nl;q=0.9']);
        self::assertSelectorTextContains('.warning', 'Gokken kan verslavend zijn. Stop op tijd!');
    }

    public function testLanguageChoiceIsRememberedAndBrowserLanguageDetected(): void
    {
        $this->client->request('GET', '/euromillions/stats', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9,en;q=0.5']);
        self::assertSelectorTextContains('h1', 'Statistik');

        $this->client->request('GET', '/euromillions/stats?lang=nl');
        self::assertResponseHasCookie('lfl_lang');

        // The cookie now wins over the browser language.
        $this->client->request('GET', '/euromillions/stats', [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE']);
        self::assertSelectorTextContains('h1', 'Statistieken');

        // Unknown languages are ignored.
        $this->client->request('GET', '/euromillions/stats?lang=xx');
        self::assertSelectorTextContains('h1', 'Statistieken');
    }

    public function testMutationsRequireACsrfToken(): void
    {
        $this->client->request('POST', '/euromillions/grids', ['grid' => '7 14 21 28 35 + 4 9', '_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/euromillions/sync', ['_token' => 'forged']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * 120 draws in 2004 (enough for the stats and the lab), one per other year.
     */
    private function writeFixtures(): void
    {
        $currentYear = (int) date('Y');
        for ($year = 2004; $year <= $currentYear; ++$year) {
            $draws = [];
            $count = 2004 === $year ? 120 : 1;
            $date = new \DateTimeImmutable($year.'-01-02');
            for ($i = 0; $i < $count; ++$i) {
                $numbers = [];
                for ($k = 0; $k < 5; ++$k) {
                    $numbers[] = 1 + (($i * 7 + $k * 11) % 50);
                }
                $draws[] = [
                    'date' => $date->modify(\sprintf('+%d days', 3 * $i))->format('Y-m-d'),
                    'numbers' => $numbers,
                    'stars' => [1 + ($i % 9), 10 + ($i % 2)],
                    'jackpot' => 17000000,
                    'jackpotWinners' => 0,
                ];
            }
            file_put_contents($this->dataDir.\sprintf('/draws-%d.json', $year), json_encode($draws, \JSON_THROW_ON_ERROR));
        }
    }

    private function removeDataDir(): void
    {
        foreach (glob($this->dataDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataDir);
    }
}
