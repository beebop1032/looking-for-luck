<?php

declare(strict_types=1);

namespace App\Tests\Euromillions;

use App\Euromillions\DrawRepository;
use App\Euromillions\EuromillionsGame;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NextJackpotTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lfl-jackpot-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testParsesTheAnnouncedJackpotFromStructuredData(): void
    {
        $jackpot = $this->repository()->parseNextJackpot(self::page('mardi 17 novembre 2026', '26'));

        self::assertNotNull($jackpot);
        self::assertSame('2026-11-17', $jackpot->drawDate->format('Y-m-d'));
        self::assertSame(26_000_000.0, $jackpot->amount);
    }

    public function testParsesDecimalAmountsAndFirstOfTheMonth(): void
    {
        $jackpot = $this->repository()->parseNextJackpot(self::page('vendredi 1er janvier 2027', '130,5'));

        self::assertNotNull($jackpot);
        self::assertSame('2027-01-01', $jackpot->drawDate->format('Y-m-d'));
        self::assertSame(130_500_000.0, $jackpot->amount);
    }

    public function testRejectsImplausibleOrMissingData(): void
    {
        $repository = $this->repository();

        self::assertNull($repository->parseNextJackpot('<html>redesigned</html>'));
        self::assertNull($repository->parseNextJackpot(self::page('mardi 17 novembre 2026', '2'))); // below the 17 M€ floor
        self::assertNull($repository->parseNextJackpot('<script type="application/ld+json">{not json</script>'));
    }

    public function testFetchesOnceThenServesTheCache(): void
    {
        $tomorrow = new \DateTimeImmutable('tomorrow');
        $months = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        $date = \sprintf('mardi %d %s %d', (int) $tomorrow->format('j'), $months[(int) $tomorrow->format('n')], (int) $tomorrow->format('Y'));

        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls, $date): MockResponse {
            ++$calls;

            return new MockResponse(self::page($date, '42'));
        });
        $repository = new DrawRepository($client, new EuromillionsGame(), $this->dir);

        $first = $repository->nextJackpot();
        $second = $repository->nextJackpot();

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame(42_000_000.0, $first->amount);
        self::assertEquals($first, $second);
        self::assertSame(1, $calls, 'the second call is served from the cache');
    }

    public function testUnreachableSourceMeansUnknown(): void
    {
        $repository = new DrawRepository(new MockHttpClient(new MockResponse('', ['http_code' => 500])), new EuromillionsGame(), $this->dir);

        self::assertNull($repository->nextJackpot());
    }

    private function repository(): DrawRepository
    {
        return new DrawRepository(new MockHttpClient(), new EuromillionsGame(), $this->dir);
    }

    private static function page(string $date, string $millions): string
    {
        $faq = [
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => [[
                '@type' => 'Question',
                'name' => 'Quel est le montant du prochain jackpot EuroMillions ?',
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => \sprintf('Le jackpot de l’EuroMillions annoncé pour le prochain tirage, le %s, est de %s millions d’euros. Il n’a pas été remporté depuis 1 tirage.', $date, $millions)],
            ]],
        ];

        return '<html><head><script type="application/ld+json">{"@type":"BreadcrumbList"}</script><script type="application/ld+json">'
            .json_encode($faq, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR).'</script></head></html>';
    }
}
