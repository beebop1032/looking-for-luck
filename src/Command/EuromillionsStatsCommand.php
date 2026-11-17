<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Euromillions\EuromillionsGame;
use App\Intl\Format;
use App\Lottery\Draw;
use App\Lottery\Lottery;
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
    use TranslatesOutput;

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
            $io->error($this->t('stats.too_short', ['count' => \count($draws)]));

            return Command::FAILURE;
        }

        $first = Format::date($draws[0]->date, 'd MMM y');
        $last = Format::date($draws[array_key_last($draws)]->date, 'd MMM y');

        Ui::banner($output, $this->t('stats.title'), $this->t('stats.range', ['count' => Format::integer(\count($draws)), 'from' => $first, 'to' => $last]));

        $latest = $draws[array_key_last($draws)];
        $io->writeln(\sprintf('  <fg=gray>%s</>   %s', $this->t('stats.latest', ['date' => $last]), Ui::grid($latest->grid)));
        $io->newLine();

        // Star frequencies are only comparable under the current rules
        // (12 stars since 2016): older draws could not hold stars 11 and 12.
        $mainFreq = $this->statistics->mainFrequencies($draws);
        $bonusFreq = $this->statistics->bonusFrequencies(Lottery::underCurrentRules($this->game, $draws));
        $mainGaps = $this->statistics->mainGaps($draws);
        $bonusGaps = $this->statistics->bonusGaps($draws);

        $this->renderHeatmap($io, $mainFreq, $bonusFreq);

        $this->renderRanking($io, $this->t('stats.most_numbers'), $mainFreq, $top, true, false);
        $this->renderRanking($io, $this->t('stats.least_numbers'), $mainFreq, $top, false, false);
        $this->renderRanking($io, $this->t('stats.most_stars'), $bonusFreq, min($top, 6), true, true);
        $this->renderRanking($io, $this->t('stats.least_stars'), $bonusFreq, min($top, 6), false, true);
        $io->writeln('  <fg=gray>'.$this->t('stats.stars_note').'</>');

        // A given number comes out on average once every pool/picked draws.
        $mainAverageGap = intdiv($this->game->mainPool(), $this->game->mainPicked());
        $bonusAverageGap = intdiv($this->game->bonusPool(), $this->game->bonusPicked());

        $io->section($this->t('stats.overdue_numbers'));
        arsort($mainGaps);
        $this->renderGaps($io, \array_slice($mainGaps, 0, $top, true), $mainAverageGap, false);

        $io->section($this->t('stats.overdue_stars'));
        arsort($bonusGaps);
        $this->renderGaps($io, \array_slice($bonusGaps, 0, 5, true), $bonusAverageGap, true);

        $io->section($this->t('stats.pairs'));
        $pairs = $this->statistics->topPairs($draws, $top);
        $maxPair = [] === $pairs ? 1 : $pairs[0]['count'];
        foreach ($pairs as $entry) {
            $io->writeln(\sprintf(
                '  %s %s  %s <options=bold>%d</>',
                Ui::ball($entry['pair'][0]),
                Ui::ball($entry['pair'][1]),
                Ui::bar($entry['count'] / $maxPair, 24),
                $entry['count'],
            ));
        }
        $io->newLine();

        $this->renderChiSquare($io, $draws);

        return Command::SUCCESS;
    }

    /**
     * @param non-empty-array<int,int> $mainFreq
     * @param non-empty-array<int,int> $bonusFreq
     */
    private function renderHeatmap(SymfonyStyle $io, array $mainFreq, array $bonusFreq): void
    {
        $io->section($this->t('stats.heatmap'));

        $this->renderHeatRows($io, $mainFreq, 10, false);
        $io->newLine();
        $this->renderHeatRows($io, $bonusFreq, 12, true);
        $io->newLine();
        $io->writeln(\sprintf(
            '  <bg=%s>   </> %s   <bg=%s>   </> %s   <bg=%s>   </> %s   <fg=gray>%s</>',
            Ui::heat(-1),
            $this->t('stats.colder'),
            Ui::heat(0),
            $this->t('stats.average'),
            Ui::heat(1),
            $this->t('stats.hotter'),
            $this->t('stats.heat_note'),
        ));
    }

    /**
     * @param non-empty-array<int,int> $frequencies
     * @param positive-int $perRow
     */
    private function renderHeatRows(SymfonyStyle $io, array $frequencies, int $perRow, bool $stars): void
    {
        $mean = array_sum($frequencies) / \count($frequencies);
        $spread = max(1.0, max(array_map(static fn (int $f): float => abs($f - $mean), $frequencies)));

        foreach (array_chunk($frequencies, $perRow, true) as $row) {
            $cells = [];
            foreach ($row as $number => $count) {
                $cells[] = \sprintf('<fg=white;bg=%s;options=bold> %s%02d </>', Ui::heat(($count - $mean) / $spread), $stars ? '★' : '', $number);
            }
            $io->writeln('  '.implode(' ', $cells));
        }
    }

    /**
     * @param non-empty-array<int,int> $frequencies
     */
    private function renderRanking(SymfonyStyle $io, string $title, array $frequencies, int $limit, bool $descending, bool $stars): void
    {
        $io->section($title);

        $sorted = $frequencies;
        $descending ? arsort($sorted) : asort($sorted);
        $total = array_sum($frequencies);
        $max = max($frequencies);

        foreach (\array_slice($sorted, 0, $limit, true) as $value => $count) {
            $io->writeln(\sprintf(
                '  %s  %s <options=bold>%4d</> <fg=gray>%s %%</>',
                $stars ? Ui::star($value) : Ui::ball($value),
                Ui::bar($count / $max, 30, $stars ? '#ffc83d' : '#2fbf71'),
                $count,
                Format::decimal(100 * $count / $total, 2),
            ));
        }
    }

    /**
     * @param array<int,int> $gaps
     */
    private function renderGaps(SymfonyStyle $io, array $gaps, int $averageGap, bool $stars): void
    {
        $max = max(1, ...array_values($gaps));
        foreach ($gaps as $value => $gap) {
            $io->writeln(\sprintf(
                '  %s  %s <options=bold>%3d</> <fg=gray>%s</>',
                $stars ? Ui::star($value) : Ui::ball($value),
                Ui::bar($gap / $max, 24, '#7aa2f7'),
                $gap,
                $this->t('stats.gap_detail', ['ratio' => Format::decimal($gap / $averageGap, 1), 'average' => $averageGap]),
            ));
        }
    }

    /**
     * @param list<Draw> $draws
     */
    private function renderChiSquare(SymfonyStyle $io, array $draws): void
    {
        $io->section($this->t('stats.chi_title'));

        // For the stars, restrict the test to draws held under constant rules
        // (12 stars since 2016-09-24), otherwise the test would merely detect
        // the rule change.
        $starDraws = Lottery::underCurrentRules($this->game, $draws);

        $mainTest = $this->statistics->uniformityTest($this->statistics->mainFrequencies($draws));
        $starTest = $this->statistics->uniformityTest($this->statistics->bonusFrequencies($starDraws));

        $verdict = fn (bool $compatible): string => $compatible
            ? '<fg=#2fbf71>✔ '.$this->t('stats.compatible').'</>'
            : '<fg=#ffc83d>⚠ '.$this->t('stats.unusual').'</>';

        $io->table(
            ['', $this->t('stats.col_statistic'), $this->t('stats.col_dof'), $this->t('stats.col_pvalue'), $this->t('stats.col_verdict')],
            [
                [$this->t('stats.row_numbers', ['count' => \count($draws)]), Format::decimal($mainTest['statistic'], 2), $mainTest['degreesOfFreedom'], Format::decimal($mainTest['pValue'], 4), $verdict($mainTest['compatible'])],
                [$this->t('stats.row_stars', ['count' => \count($starDraws)]), Format::decimal($starTest['statistic'], 2), $starTest['degreesOfFreedom'], Format::decimal($starTest['pValue'], 4), $verdict($starTest['compatible'])],
            ]
        );

        Ui::note($io, $this->t('stats.chi_explain'));
        Ui::note($io, $this->t('stats.chi_high'));
        Ui::note($io, $this->t('stats.chi_low'));
    }
}
