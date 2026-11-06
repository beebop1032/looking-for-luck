<?php

declare(strict_types=1);

namespace App\Euromillions;

use App\Lottery\Draw;
use App\Lottery\Grid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the full EuroMillions draw history (since 2004) with a local
 * on-disk cache.
 *
 * Security:
 *  - requests go through the HttpClient component (TLS verified by default,
 *    timeout, capped redirects), against a single hard-coded host;
 *  - every parsed value is validated (ranges, cardinalities) before use;
 *  - the cache is written atomically under var/euromillions/.
 */
final class DrawRepository
{
    private const BASE_URL = 'https://www.tirage-euromillions.net/euromillions/annees/annee-%d/';
    private const FIRST_YEAR = 2004;
    private const CURRENT_YEAR_TTL = 21600; // 6 hours for the current year

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EuromillionsGame $game,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Every known draw, oldest first.
     *
     * @return list<Draw>
     */
    public function all(?int $fromYear = null): array
    {
        $fromYear = max(self::FIRST_YEAR, $fromYear ?? self::FIRST_YEAR);
        $currentYear = (int) date('Y');

        $draws = [];
        for ($year = $fromYear; $year <= $currentYear; ++$year) {
            foreach ($this->yearDraws($year) as $draw) {
                $draws[] = $draw;
            }
        }

        usort($draws, static fn (Draw $a, Draw $b): int => $a->date <=> $b->date);

        // Deduplicate by date (one date = one draw).
        $byDate = [];
        foreach ($draws as $draw) {
            $byDate[$draw->date->format('Y-m-d')] = $draw;
        }

        return array_values($byDate);
    }

    public function latest(): ?Draw
    {
        $draws = $this->all((int) date('Y') - 1);

        return [] === $draws ? null : $draws[array_key_last($draws)];
    }

    /**
     * @return list<Draw>
     */
    private function yearDraws(int $year): array
    {
        $cacheFile = $this->cacheDir().\sprintf('/draws-%d.json', $year);
        $isCurrentYear = $year === (int) date('Y');

        if (is_file($cacheFile)) {
            $fresh = !$isCurrentYear || (time() - (int) filemtime($cacheFile)) < self::CURRENT_YEAR_TTL;
            if ($fresh) {
                $cached = $this->readCache($cacheFile);
                if (null !== $cached) {
                    return $cached;
                }
            }
        }

        try {
            $draws = $this->fetchYear($year);
        } catch (\Throwable $e) {
            // Fall back to a stale cache rather than failing outright.
            $stale = is_file($cacheFile) ? $this->readCache($cacheFile) : null;
            if (null !== $stale) {
                return $stale;
            }

            throw new \RuntimeException(\sprintf('Unable to fetch the %d draws: %s', $year, $e->getMessage()), 0, $e);
        }

        $this->writeCache($cacheFile, $draws);

        return $draws;
    }

    /**
     * @return list<Draw>
     */
    private function fetchYear(int $year): array
    {
        $url = \sprintf(self::BASE_URL, $year);

        $response = $this->httpClient->request('GET', $url, [
            'timeout' => 15,
            'max_redirects' => 3,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            ],
        ]);

        $html = $response->getContent(); // throws on status >= 300 after redirects

        return $this->parseHtml($html, $year);
    }

    /**
     * @return list<Draw>
     */
    private function parseHtml(string $html, int $year): array
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html, \LIBXML_NOERROR | \LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//table[contains(@class,"blue_table")]//tr[td]');
        if (false === $rows) {
            return [];
        }

        $draws = [];
        foreach ($rows as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }

            $ballNodes = $xpath->query('.//span[contains(@class,"ball_small")]', $row);
            $starNodes = $xpath->query('.//span[contains(@class,"star_small")]', $row);
            if (false === $ballNodes || false === $starNodes) {
                continue;
            }

            if ($ballNodes->length !== $this->game->mainPicked() || $starNodes->length !== $this->game->bonusPicked()) {
                continue; // draw not held yet, or stray row
            }

            $dateCells = $xpath->query('.//td[1]', $row);
            $dateNode = false === $dateCells ? null : $dateCells->item(0);
            $rawDate = $dateNode instanceof \DOMElement ? trim($dateNode->textContent) : '';
            if (1 !== preg_match('~(\d{2})/(\d{2})/(\d{4})~', $rawDate, $m)) {
                continue;
            }
            if ((int) $m[3] !== $year) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!d/m/Y', \sprintf('%s/%s/%s', $m[1], $m[2], $m[3]));
            if (false === $date) {
                continue;
            }

            $numbers = [];
            foreach ($ballNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $numbers[] = (int) trim($node->textContent);
                }
            }
            $stars = [];
            foreach ($starNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $stars[] = (int) trim($node->textContent);
                }
            }

            try {
                $draws[] = new Draw($date, Grid::of($this->game, $numbers, $stars));
            } catch (\InvalidArgumentException) {
                // Out-of-range data from the site: skip instead of propagating.
                continue;
            }
        }

        return $draws;
    }

    private function cacheDir(): string
    {
        $dir = $this->projectDir.'/var/euromillions';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create cache directory "%s".', $dir));
        }

        return $dir;
    }

    /**
     * @return list<Draw>|null
     */
    private function readCache(string $file): ?array
    {
        $raw = @file_get_contents($file);
        if (false === $raw) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        $draws = [];
        foreach ($decoded as $item) {
            if (!\is_array($item) || !isset($item['date'], $item['numbers'], $item['stars'])
                || !\is_string($item['date']) || !\is_array($item['numbers']) || !\is_array($item['stars'])) {
                return null;
            }
            try {
                $date = new \DateTimeImmutable($item['date']);
                $draws[] = new Draw($date, Grid::of($this->game, $item['numbers'], $item['stars']));
            } catch (\Throwable) {
                return null; // corrupted cache: refetch
            }
        }

        return $draws;
    }

    /**
     * @param list<Draw> $draws
     */
    private function writeCache(string $file, array $draws): void
    {
        $payload = array_map(static fn (Draw $d): array => [
            'date' => $d->date->format('Y-m-d'),
            'numbers' => $d->grid->main,
            'stars' => $d->grid->bonus,
        ], $draws);

        $tmp = $file.'.tmp';
        if (false !== @file_put_contents($tmp, json_encode($payload, \JSON_THROW_ON_ERROR))) {
            @rename($tmp, $file);
        }
    }
}
