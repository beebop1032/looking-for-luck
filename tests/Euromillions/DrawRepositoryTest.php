<?php

declare(strict_types=1);

namespace App\Tests\Euromillions;

use App\Euromillions\DrawRepository;
use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class DrawRepositoryTest extends TestCase
{
    private const CSV = "\u{FEFF}\"Tirages EuroMillions 2026\"\n"
        ."Date;\"Tirage n°\";Édition;Numéros;Étoiles;Jackpot;\"Gagnants du rang 1\";\"My Million\"\n"
        ."02/01/2026;1908;;\"8 27 42 44 46\";\"1 10\";30879489;0;\"QI 521 4040\"\n"
        ."06/01/2026;1909;;\"5 14 17 18 31\";\"10 12\";40421786;1;\"QI 123 4567\"\n"
        ."09/01/2026;1910;;\"5 14 17 18\";\"10 12\";;;\n"           // incomplete: skipped
        ."13/01/2025;1911;;\"1 2 3 4 5\";\"1 2\";;;\n"              // other year: skipped
        ."16/01/2026;1912;;\"1 2 3 4 99\";\"1 2\";;;\n"             // out of range: skipped
        ."\"91 tirages, du vendredi 2 janvier 2026 au vendredi 13 novembre 2026\"\n";

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/lfl-draws-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testParsesTheCsvExport(): void
    {
        $repository = new DrawRepository(new MockHttpClient(), new EuromillionsGame(), $this->dir);

        $draws = $repository->parseCsv(self::CSV, 2026);

        self::assertCount(2, $draws);
        self::assertSame('2026-01-02', $draws[0]->date->format('Y-m-d'));
        self::assertSame([8, 27, 42, 44, 46], $draws[0]->grid->main);
        self::assertSame([1, 10], $draws[0]->grid->bonus);
        self::assertSame(30879489.0, $draws[0]->jackpot);
        self::assertSame(0, $draws[0]->jackpotWinners);
        self::assertSame(1, $draws[1]->jackpotWinners);
    }

    public function testAFailedRefreshIsAnErrorAndNeverOverwritesTheCache(): void
    {
        $year = (int) date('Y');
        mkdir($this->dir, 0775, true);
        $cached = json_encode([['date' => $year.'-01-02', 'numbers' => [1, 2, 3, 4, 5], 'stars' => [1, 2], 'jackpot' => null, 'jackpotWinners' => null]], \JSON_THROW_ON_ERROR);
        foreach ([$year - 1, $year] as $y) {
            file_put_contents($this->dir.\sprintf('/draws-%d.json', $y), $cached);
        }

        // The source answers 200 with a page we cannot parse.
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('<html>redesigned</html>'));
        $repository = new DrawRepository($client, new EuromillionsGame(), $this->dir);

        try {
            $repository->refresh();
            self::fail('A refresh that downloaded nothing must not report the draws as up to date.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString((string) $year, $e->getMessage());
        }
        self::assertSame($cached, file_get_contents($this->dir.\sprintf('/draws-%d.json', $year)));
    }

    public function testACacheWithoutJackpotsIsDownloadedAgainButStillServesWhenOffline(): void
    {
        $year = 2024; // a past year, unlike the "other year" line of the CSV
        mkdir($this->dir, 0775, true);
        $old = json_encode([['date' => $year.'-03-01', 'numbers' => [1, 2, 3, 4, 5], 'stars' => [1, 2]]], \JSON_THROW_ON_ERROR);
        file_put_contents($this->dir.\sprintf('/draws-%d.json', $year), $old);
        touch($this->dir.\sprintf('/draws-%d.json', $year), time() - 86_400); // written by an older version
        $yearDraws = new \ReflectionMethod(DrawRepository::class, 'yearDraws');

        // Online: the old-format cache is replaced by the CSV, with jackpots.
        $csv = str_replace('/2026;', '/2024;', self::CSV);
        $online = new DrawRepository(new MockHttpClient(new MockResponse($csv)), new EuromillionsGame(), $this->dir);
        $draws = $yearDraws->invoke($online, $year);
        self::assertIsArray($draws);
        self::assertCount(2, $draws);
        self::assertInstanceOf(Draw::class, $draws[0]);
        self::assertSame(30879489.0, $draws[0]->jackpot);

        // Offline: the old-format cache is still better than nothing, and the
        // download is retried once per TTL, not on every call.
        file_put_contents($this->dir.\sprintf('/draws-%d.json', $year), $old);
        touch($this->dir.\sprintf('/draws-%d.json', $year), time() - 86_400);
        $requests = 0;
        $client = new MockHttpClient(static function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('', ['http_code' => 503]);
        });
        $offline = new DrawRepository($client, new EuromillionsGame(), $this->dir);
        foreach ([1, 2, 3] as $call) {
            $draws = $yearDraws->invoke($offline, $year);
            self::assertIsArray($draws);
            self::assertCount(1, $draws);
        }
        self::assertSame(1, $requests);
    }
}
