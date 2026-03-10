<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:euromillions:predict',
    description: 'Propose des combinaisons Euromillions à partir des 20 derniers tirages crawlés.',
)]
class EuromillionsPredictCommand extends Command
{
    private const MAIN_MIN = 1;
    private const MAIN_MAX = 50;
    private const STAR_MIN = 1;
    private const STAR_MAX = 12;

    protected function configure(): void
    {
        $this
            ->addArgument('count', InputArgument::REQUIRED, 'Nombre de prédictions à générer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = (int) $input->getArgument('count');

        if ($count < 1 || $count > 50) {
            $io->error('Le paramètre "count" doit être compris entre 1 et 50.');

            return Command::INVALID;
        }

        try {
            $draws = $this->fetchLastDraws(20);
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $predictions = $this->buildPredictions($draws, $count);

        $io->title('Propositions Euromillions (basées sur les 20 derniers tirages)');
        $io->writeln(sprintf('Tirages analysés: <info>%d</info>', count($draws)));
        $io->newLine();

        $rows = [];
        foreach ($predictions as $index => $prediction) {
            $rows[] = [
                $index + 1,
                implode(' ', array_map(static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT), $prediction['numbers'])),
                implode(' ', array_map(static fn (int $n): string => str_pad((string) $n, 2, '0', STR_PAD_LEFT), $prediction['stars'])),
            ];
        }

        $io->table(['#', 'Numéros', 'Étoiles'], $rows);
        $io->note('Ces combinaisons sont des essais statistiques, pas une garantie de gain.');

        return Command::SUCCESS;
    }

    /**
     * @return list<array{numbers:list<int>,stars:list<int>}>
     */
    private function fetchLastDraws(int $limit): array
    {
        $sources = [
            'https://www.fdj.fr/api/service-draw-games/v1/games/euromillions/draws?limit=' . $limit,
            'https://www.fdj.fr/api/service-draw-games/v1/games/euromillions/draws?range=0-' . $limit,
        ];

        foreach ($sources as $url) {
            $raw = $this->httpGet($url);
            if ($raw === null) {
                continue;
            }

            $draws = $this->extractDrawsFromJson($raw);
            if (count($draws) >= $limit) {
                return array_slice($draws, 0, $limit);
            }
        }

        throw new \RuntimeException('Impossible de crawler les tirages Euromillions (source distante indisponible ou format inattendu).');
    }

    private function httpGet(string $url): ?string
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 CodexSymfonyCommand\r\n",
                'timeout' => 10,
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        return is_string($result) && $result !== '' ? $result : null;
    }

    /**
     * @return list<array{numbers:list<int>,stars:list<int>}>
     */
    private function extractDrawsFromJson(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $draws = [];
        foreach ($this->flattenArray($decoded) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $numbers = $this->extractIntList($candidate, ['numbers', 'regularNumbers', 'mainNumbers', 'boules', 'balls']);
            $stars = $this->extractIntList($candidate, ['stars', 'luckyStars', 'starNumbers', 'etoiles']);

            if (count($numbers) !== 5 || count($stars) !== 2) {
                continue;
            }

            sort($numbers);
            sort($stars);
            $draws[] = ['numbers' => $numbers, 'stars' => $stars];
        }

        return array_values(array_unique($draws, SORT_REGULAR));
    }

    /**
     * @return list<mixed>
     */
    private function flattenArray(array $data): array
    {
        $flat = [$data];

        foreach ($data as $value) {
            if (is_array($value)) {
                $flat = [...$flat, ...$this->flattenArray($value)];
            }
        }

        return $flat;
    }

    /**
     * @param list<string> $keys
     * @return list<int>
     */
    private function extractIntList(array $candidate, array $keys): array
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $candidate) || !is_array($candidate[$key])) {
                continue;
            }

            $values = array_values(array_filter(
                array_map(static fn ($value): int => (int) $value, $candidate[$key]),
                static fn (int $value): bool => $value > 0
            ));

            if ($values !== []) {
                return $values;
            }
        }

        return [];
    }

    /**
     * @param list<array{numbers:list<int>,stars:list<int>}> $draws
     * @return list<array{numbers:list<int>,stars:list<int>}>
     */
    private function buildPredictions(array $draws, int $count): array
    {
        $mainFrequency = array_fill(self::MAIN_MIN, self::MAIN_MAX, 0);
        $starFrequency = array_fill(self::STAR_MIN, self::STAR_MAX, 0);

        foreach ($draws as $draw) {
            foreach ($draw['numbers'] as $number) {
                ++$mainFrequency[$number];
            }
            foreach ($draw['stars'] as $star) {
                ++$starFrequency[$star];
            }
        }

        $hotMain = $this->sortedPool($mainFrequency, true, 20);
        $coldMain = $this->sortedPool($mainFrequency, false, 20);
        $hotStars = $this->sortedPool($starFrequency, true, 8);
        $coldStars = $this->sortedPool($starFrequency, false, 8);

        $predictions = [];
        $seen = [];

        while (count($predictions) < $count) {
            $numbers = [];
            while (count($numbers) < 3) {
                $pick = $this->weightedPick($hotMain);
                if (!in_array($pick, $numbers, true)) {
                    $numbers[] = $pick;
                }
            }
            while (count($numbers) < 5) {
                $pick = $this->weightedPick($coldMain);
                if (!in_array($pick, $numbers, true)) {
                    $numbers[] = $pick;
                }
            }

            $stars = [];
            $stars[] = $this->weightedPick($hotStars);
            do {
                $secondStar = $this->weightedPick($coldStars);
            } while ($secondStar === $stars[0]);
            $stars[] = $secondStar;

            sort($numbers);
            sort($stars);

            $signature = implode('-', $numbers) . '|' . implode('-', $stars);
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $predictions[] = ['numbers' => $numbers, 'stars' => $stars];
        }

        return $predictions;
    }

    /**
     * @param array<int,int> $frequencies
     * @return array<int,int>
     */
    private function sortedPool(array $frequencies, bool $descending, int $limit): array
    {
        $pool = $frequencies;
        $descending ? arsort($pool) : asort($pool);

        return array_slice($pool, 0, $limit, true);
    }

    /**
     * @param array<int,int> $weightedPool
     */
    private function weightedPick(array $weightedPool): int
    {
        $adjusted = [];
        foreach ($weightedPool as $value => $weight) {
            $adjusted[$value] = $weight + 1;
        }

        $total = array_sum($adjusted);
        $random = random_int(1, $total);

        $cursor = 0;
        foreach ($adjusted as $value => $weight) {
            $cursor += $weight;
            if ($random <= $cursor) {
                return (int) $value;
            }
        }

        return (int) array_key_first($adjusted);
    }
}
