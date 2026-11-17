<?php

declare(strict_types=1);

namespace App\Euromillions;

use App\Lottery\Draw;
use App\Lottery\DrawSource;
use App\Lottery\Grid;
use App\Lottery\NextJackpot;
use App\Lottery\NextJackpotSource;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches the full EuroMillions draw history (since 2004) — one CSV export
 * per year — with a local on-disk cache.
 *
 * Security:
 *  - requests go through the HttpClient component (TLS verified by default,
 *    timeout, capped redirects), against a single hard-coded host;
 *  - every parsed value is validated (ranges, cardinalities) before use;
 *  - the cache is written atomically under var/euromillions/.
 */
#[AsAlias(DrawSource::class)]
final class DrawRepository implements DrawSource, NextJackpotSource
{
    private const BASE_URL = 'https://www.tirage-euromillions.net/euromillions/annees/annee-%d/?export=annee&format=csv';
    private const FIRST_YEAR = 2004;
    private const CURRENT_YEAR_TTL = 21600; // 6 hours for the current year
    private const JACKPOT_URL = 'https://www.tirage-euromillions.net/euromillions/';
    private const JACKPOT_TTL = 10800; // 3 hours
    private const MONTHS = ['janvier' => 1, 'février' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6, 'juillet' => 7, 'août' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'décembre' => 12];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly EuromillionsGame $game,
        #[Autowire('%app.data_dir%')]
        private readonly string $dataDir,
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

    /**
     * Forces a re-download of the most recent year(s), ignoring the cache
     * TTL: used by the scheduled sync and the "Check for new draws" button.
     * Unlike all(), a failed download is an error, never hidden behind the
     * stale cache: the caller must not report the draws as up to date.
     *
     * @return list<Draw> every known draw, oldest first
     *
     * @throws \RuntimeException when the download fails
     */
    public function refresh(): array
    {
        $currentYear = (int) date('Y');
        // Early January: the last draws of December may not be cached yet.
        $years = 1 === (int) date('n') ? [$currentYear - 1, $currentYear] : [$currentYear];
        foreach ($years as $year) {
            $this->yearDraws($year, true);
        }

        return $this->all();
    }

    /**
     * The jackpot announced for the next draw, read from the structured data
     * (schema.org FAQPage) of the source's EuroMillions page, cached 3 hours.
     */
    public function nextJackpot(bool $forceRefresh = false): ?NextJackpot
    {
        $cacheFile = $this->cacheDir().'/next-jackpot.json';
        $today = new \DateTimeImmutable('today');

        $cached = $this->readJackpotCache($cacheFile);
        $fresh = null !== $cached && (time() - (int) filemtime($cacheFile)) < self::JACKPOT_TTL;
        if (!$forceRefresh && $fresh && $cached->drawDate >= $today) {
            return $cached;
        }

        try {
            $response = $this->httpClient->request('GET', self::JACKPOT_URL, [
                'timeout' => 10,
                'max_redirects' => 3,
                'headers' => ['User-Agent' => 'looking-for-luck (+https://github.com/beebop1032/looking-for-luck)'],
            ]);
            $jackpot = $this->parseNextJackpot($response->getContent());
        } catch (\Throwable) {
            $jackpot = null;
        }

        if (null === $jackpot) {
            // Keep a stale value only while it still announces an upcoming draw.
            return null !== $cached && $cached->drawDate >= $today ? $cached : null;
        }

        $payload = json_encode(['drawDate' => $jackpot->drawDate->format('Y-m-d'), 'amount' => $jackpot->amount], \JSON_THROW_ON_ERROR);
        if (false !== @file_put_contents($cacheFile.'.tmp', $payload)) {
            @rename($cacheFile.'.tmp', $cacheFile);
        }

        return $jackpot;
    }

    /**
     * Looks for the FAQ answer "… pour le prochain tirage, le mardi 17 novembre
     * 2026, est de 26 millions d’euros" in the page's JSON-LD blocks.
     */
    public function parseNextJackpot(string $html): ?NextJackpot
    {
        if (false === preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $blocks)) {
            return null;
        }

        foreach ($blocks[1] as $json) {
            try {
                $data = json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!\is_array($data) || 'FAQPage' !== ($data['@type'] ?? null) || !\is_array($data['mainEntity'] ?? null)) {
                continue;
            }

            foreach ($data['mainEntity'] as $question) {
                $answer = \is_array($question) && \is_array($question['acceptedAnswer'] ?? null) ? ($question['acceptedAnswer']['text'] ?? null) : null;
                if (!\is_string($answer)
                    || 1 !== preg_match('~prochain tirage, le \p{L}+ (\d{1,2})(?:er)? (\p{L}+) (\d{4}), est de ([\d\s\x{202F}\x{00A0},.]+) millions?~u', $answer, $m)) {
                    continue;
                }

                $month = self::MONTHS[mb_strtolower($m[2])] ?? null;
                $amount = (float) str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], trim($m[4])) * 1_000_000;
                $date = null !== $month ? \DateTimeImmutable::createFromFormat('!Y-n-j', \sprintf('%d-%d-%d', $m[3], $month, $m[1])) : false;

                // Sanity bounds: the game's minimum and maximum jackpots.
                if (false !== $date && $amount >= $this->game->minimumJackpot() && $amount <= $this->game->maximumJackpot()) {
                    return new NextJackpot($date, $amount);
                }
            }
        }

        return null;
    }

    public function firstYear(): int
    {
        return self::FIRST_YEAR;
    }

    public function latest(): ?Draw
    {
        $draws = $this->all((int) date('Y') - 1);

        return [] === $draws ? null : $draws[array_key_last($draws)];
    }

    /**
     * @return list<Draw>
     */
    private function yearDraws(int $year, bool $force = false): array
    {
        $cacheFile = $this->cacheDir().\sprintf('/draws-%d.json', $year);
        $isCurrentYear = $year === (int) date('Y');

        if (!$force && is_file($cacheFile)) {
            $recent = (time() - (int) filemtime($cacheFile)) < self::CURRENT_YEAR_TTL;
            // A past year never changes, unless its cache predates the jackpot columns.
            if ($recent || !$isCurrentYear) {
                $cached = $this->readCache($cacheFile, true);
                if (null !== $cached) {
                    return $cached;
                }
            }
            // An old-format cache whose download failed recently: served as is,
            // and retried at most once per TTL rather than on every call.
            if ($recent) {
                $cached = $this->readCache($cacheFile);
                if (null !== $cached) {
                    return $cached;
                }
            }
        }

        try {
            $draws = $this->fetchYear($year);
            // An empty answer for a year that must hold draws means the source
            // changed or failed: never let it overwrite a good cache.
            if ([] === $draws && !$this->mayBeEmpty($year)) {
                throw new \RuntimeException('the source returned no draws');
            }
        } catch (\Throwable $e) {
            // Fall back to a stale cache rather than failing outright, except
            // when a fresh download was explicitly asked for.
            $stale = !$force && is_file($cacheFile) ? $this->readCache($cacheFile) : null;
            if (null !== $stale) {
                @touch($cacheFile); // next attempt after the TTL, not on the next call

                return $stale;
            }

            throw new \RuntimeException(\sprintf('Unable to fetch the %d draws: %s', $year, $e->getMessage()), 0, $e);
        }

        $this->writeCache($cacheFile, $draws);

        return $draws;
    }

    /**
     * The current year has no draw before its first Tuesday/Friday.
     */
    private function mayBeEmpty(int $year): bool
    {
        return $year === (int) date('Y') && (int) date('z') < 7;
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
                'User-Agent' => 'looking-for-luck (+https://github.com/beebop1032/looking-for-luck)',
                'Accept' => 'text/csv',
            ],
        ]);

        return $this->parseCsv($response->getContent(), $year); // throws on status >= 300 after redirects
    }

    /**
     * Expected columns: Date;Tirage n°;Édition;Numéros;Étoiles;Jackpot;Gagnants du rang 1;…
     * Title, header and footer lines are skipped because they do not start
     * with a date of the requested year.
     *
     * @return list<Draw>
     */
    public function parseCsv(string $csv, int $year): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;

        $draws = [];
        foreach (preg_split('/\R/', $csv) ?: [] as $line) {
            $cells = str_getcsv($line, ';', '"', '');
            if (\count($cells) < 5 || !\is_string($cells[0]) || 1 !== preg_match('~^(\d{2})/(\d{2})/(\d{4})$~', trim($cells[0]), $m)) {
                continue;
            }
            if ((int) $m[3] !== $year) {
                continue;
            }

            $date = \DateTimeImmutable::createFromFormat('!d/m/Y', $m[0]);
            if (false === $date) {
                continue;
            }

            $numbers = array_map(intval(...), preg_split('/\s+/', trim((string) $cells[3])) ?: []);
            $stars = array_map(intval(...), preg_split('/\s+/', trim((string) $cells[4])) ?: []);
            if (\count($numbers) !== $this->game->mainPicked() || \count($stars) !== $this->game->bonusPicked()) {
                continue; // draw not held yet, or stray row
            }

            $jackpot = isset($cells[5]) && ctype_digit(trim((string) $cells[5])) ? (float) trim((string) $cells[5]) : null;
            $winners = isset($cells[6]) && ctype_digit(trim((string) $cells[6])) ? (int) trim((string) $cells[6]) : null;

            try {
                $draws[] = new Draw($date, Grid::of($this->game, $numbers, $stars), $jackpot, $winners);
            } catch (\InvalidArgumentException) {
                // Out-of-range data from the source: skip instead of propagating.
                continue;
            }
        }

        return $draws;
    }

    private function readJackpotCache(string $file): ?NextJackpot
    {
        $raw = @file_get_contents($file);
        if (false === $raw) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 4, \JSON_THROW_ON_ERROR);
            if (!\is_array($data) || !\is_string($data['drawDate'] ?? null) || !is_numeric($data['amount'] ?? null)) {
                return null;
            }

            return new NextJackpot(new \DateTimeImmutable($data['drawDate']), (float) $data['amount']);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheDir(): string
    {
        $dir = $this->dataDir;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(\sprintf('Unable to create cache directory "%s".', $dir));
        }

        return $dir;
    }

    /**
     * @param bool $currentFormat null for a cache written before the jackpot
     *                            columns existed, so that it is downloaded again
     *
     * @return list<Draw>|null
     */
    private function readCache(string $file, bool $currentFormat = false): ?array
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
                || !\is_string($item['date']) || !\is_array($item['numbers']) || !\is_array($item['stars'])
                || ($currentFormat && !\array_key_exists('jackpot', $item))) {
                return null;
            }
            try {
                $date = new \DateTimeImmutable($item['date']);
                $jackpot = $item['jackpot'] ?? null;
                $winners = $item['jackpotWinners'] ?? null;
                $draws[] = new Draw(
                    $date,
                    Grid::of($this->game, $item['numbers'], $item['stars']),
                    is_numeric($jackpot) ? (float) $jackpot : null,
                    \is_int($winners) ? $winners : null,
                );
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
            'jackpot' => $d->jackpot,
            'jackpotWinners' => $d->jackpotWinners,
        ], $draws);

        $tmp = $file.'.tmp';
        if (false !== @file_put_contents($tmp, json_encode($payload, \JSON_THROW_ON_ERROR))) {
            @rename($tmp, $file);
        }
    }
}
