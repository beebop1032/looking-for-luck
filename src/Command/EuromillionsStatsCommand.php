<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Euromillions\EuromillionsGame;
use App\Lottery\Draw;
use App\Lottery\Statistics;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:euromillions:stats',
    description: 'Draw statistics: frequencies, gaps, pairs, chi-squared test.',
)]
final class EuromillionsStatsCommand extends Command
{
    public function __construct(
        private readonly DrawRepository $repository,
        private readonly EuromillionsGame $game,
        private readonly Statistics $statistics,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('since-year', null, InputOption::VALUE_REQUIRED, 'Analyse from this year onwards (default: 2004, the whole history)')
            ->addOption('top', null, InputOption::VALUE_REQUIRED, 'Size of the displayed rankings', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $top = max(3, min(50, InputValue::int($input->getOption('top'))));
        $sinceYearOption = $input->getOption('since-year');
        $sinceYear = null !== $sinceYearOption ? InputValue::int($sinceYearOption) : null;

        try {
            $draws = $this->repository->all($sinceYear);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if (\count($draws) < 50) {
            $io->error(\sprintf('History too short (%d draws) for reliable statistics.', \count($draws)));

            return Command::FAILURE;
        }

        $first = $draws[0]->date->format('d/m/Y');
        $last = $draws[array_key_last($draws)]->date->format('d/m/Y');

        $io->title(\sprintf('EuroMillions statistics — %d draws from %s to %s', \count($draws), $first, $last));

        $mainFreq = $this->statistics->mainFrequencies($draws);
        $bonusFreq = $this->statistics->bonusFrequencies($draws);
        $mainGaps = $this->statistics->mainGaps($draws);
        $bonusGaps = $this->statistics->bonusGaps($draws);

        $this->renderRanking($io, 'Most drawn numbers', $mainFreq, $top, true);
        $this->renderRanking($io, 'Least drawn numbers', $mainFreq, $top, false);
        $this->renderRanking($io, 'Most drawn stars', $bonusFreq, min($top, 6), true);
        $this->renderRanking($io, 'Least drawn stars', $bonusFreq, min($top, 6), false);
        $io->text('<comment>Note:</comment> stars 10-12 only exist since 2011/2016 — their raw frequencies are mechanically lower.');

        // A given number comes out on average once every pool/picked draws.
        $mainAverageGap = intdiv($this->game->mainPool(), $this->game->mainPicked());
        $bonusAverageGap = intdiv($this->game->bonusPool(), $this->game->bonusPicked());

        $io->section('"Overdue" numbers (gap = draws since last appearance)');
        arsort($mainGaps);
        $rows = [];
        foreach (\array_slice($mainGaps, 0, $top, true) as $number => $gap) {
            $rows[] = [$number, $gap, \sprintf('%.1f× the average gap (%d)', $gap / $mainAverageGap, $mainAverageGap)];
        }
        $io->table(['Number', 'Current gap', 'Reading'], $rows);

        $io->section('"Overdue" stars');
        arsort($bonusGaps);
        $rows = [];
        foreach (\array_slice($bonusGaps, 0, 5, true) as $star => $gap) {
            $rows[] = [$star, $gap, \sprintf('%.1f× the average gap (%d)', $gap / $bonusAverageGap, $bonusAverageGap)];
        }
        $io->table(['Star', 'Current gap', 'Reading'], $rows);

        $io->section('Most frequent pairs of numbers');
        $rows = [];
        foreach ($this->statistics->topPairs($draws, $top) as $entry) {
            $rows[] = [\sprintf('%d & %d', $entry['pair'][0], $entry['pair'][1]), $entry['count']];
        }
        $io->table(['Pair', 'Drawn together'], $rows);

        $this->renderChiSquare($io, $draws);

        return Command::SUCCESS;
    }

    /**
     * @param array<int,int> $frequencies
     */
    private function renderRanking(SymfonyStyle $io, string $title, array $frequencies, int $limit, bool $descending): void
    {
        $io->section($title);

        $sorted = $frequencies;
        $descending ? arsort($sorted) : asort($sorted);
        $total = array_sum($frequencies);

        $rows = [];
        foreach (\array_slice($sorted, 0, $limit, true) as $value => $count) {
            $rows[] = [$value, $count, \sprintf('%.2f %%', 100 * $count / $total)];
        }

        $io->table(['Value', 'Draws', 'Share'], $rows);
    }

    /**
     * @param list<Draw> $draws
     */
    private function renderChiSquare(SymfonyStyle $io, array $draws): void
    {
        $io->section('Chi-squared test: is the draw compatible with pure randomness?');

        // For the stars, restrict the test to draws held under constant rules
        // (12 stars since 2016-09-24), otherwise the test would merely detect
        // the rule change.
        $starDraws = $this->game->drawsUnderCurrentStarRules($draws);

        $mainTest = $this->statistics->uniformityTest($this->statistics->mainFrequencies($draws));
        $starTest = $this->statistics->uniformityTest($this->statistics->bonusFrequencies($starDraws));

        $io->table(
            ['', 'χ² statistic', 'Degrees of freedom', 'p-value', 'Verdict'],
            [
                [\sprintf('Numbers (1-50, %d draws)', \count($draws)), \sprintf('%.2f', $mainTest['statistic']), $mainTest['degreesOfFreedom'], \sprintf('%.4f', $mainTest['pValue']), $mainTest['compatible'] ? 'Compatible with randomness' : 'Unusual deviation'],
                [\sprintf('Stars (1-12, %d draws since 09/2016)', \count($starDraws)), \sprintf('%.2f', $starTest['statistic']), $starTest['degreesOfFreedom'], \sprintf('%.4f', $starTest['pValue']), $starTest['compatible'] ? 'Compatible with randomness' : 'Unusual deviation'],
            ]
        );

        $io->text([
            '<comment>In plain language:</comment> this test compares the observed frequencies to what',
            'a perfectly random draw would produce. The p-value is the probability of',
            'observing deviations at least this large if randomness is pure.',
            '',
            ' · p-value ≥ 0.05: the gaps between "hot" and "cold" numbers are exactly',
            '   what randomness produces. No number is favoured; past frequencies say',
            '   nothing about future draws.',
            ' · p-value < 0.05: the observed distribution would be unusual for a fair',
            '   draw (still possible: 5% of perfectly random histories fall below this',
            '   threshold out of sheer bad luck).',
        ]);
    }
}
