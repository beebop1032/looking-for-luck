<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Intl\Format;
use App\Lottery\BudgetSimulator;
use App\Lottery\PrizeTable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:euromillions:budget',
    description: 'Budget simulator: Monte Carlo over N years of play (median, percentiles, expected loss).',
)]
final class EuromillionsBudgetCommand extends Command
{
    use TranslatesOutput;

    public function __construct(
        private readonly BudgetSimulator $simulator,
        private readonly PrizeTable $prizeTable,
        private readonly DrawRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('years', null, InputOption::VALUE_REQUIRED, 'Number of simulated years of play', '10')
            ->addOption('price', null, InputOption::VALUE_REQUIRED, 'Price of one grid in euros', '2.5')
            ->addOption('grids-per-draw', null, InputOption::VALUE_REQUIRED, 'Grids played at each draw', '1')
            ->addOption('simulations', null, InputOption::VALUE_REQUIRED, 'Number of Monte Carlo trajectories', '10000')
            ->addOption('jackpot', null, InputOption::VALUE_REQUIRED, 'Jackpot amount used for tier 1 (€); default: the jackpot announced for the next draw');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $years = InputValue::int($input->getOption('years'));
        $price = InputValue::float($input->getOption('price'));
        $gridsPerDraw = InputValue::int($input->getOption('grids-per-draw'));
        $simulations = max(1_000, min(200_000, InputValue::int($input->getOption('simulations'))));
        $jackpotOption = $input->getOption('jackpot');
        $jackpot = null !== $jackpotOption
            ? InputValue::float($jackpotOption)
            : ($this->repository->nextJackpot()->amount ?? null);

        $table = null !== $jackpot ? $this->prizeTable->withJackpot($jackpot) : $this->prizeTable;

        try {
            $result = $this->simulator->simulate($years, $gridsPerDraw, $price, $simulations, $table);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $chances = $this->simulator->chances($result['gridsPlayed'], $table);
        $perWeek = $result['totalCost'] / ($years * 52);
        $back = $result['wonBack'];

        Ui::banner($output, $this->t('budget.title'), $this->t('budget.subtitle', [
            'years' => $years,
            'grids' => $gridsPerDraw,
            'price' => Format::money($price),
            'jackpot' => Format::money($table->prizeFor(1), 0),
        ]));

        $io->section($this->t('budget.spend_title'));
        $io->writeln('  '.$this->t('budget.spend_line', [
            'week' => '<options=bold>'.Format::money($perWeek).'</>',
            'year' => '<options=bold>'.Format::money($perWeek * 52, 0).'</>',
            'years' => $years,
            'total' => '<options=bold>'.Format::money($result['totalCost'], 0).'</>',
            'grids' => Format::integer($result['gridsPlayed']),
        ]));

        $io->section($this->t('budget.chances_title', ['years' => $years]));
        $io->writeln('  '.$this->t('budget.any_prize', [
            'chance' => '<options=bold>'.Ui::chance($chances['any']['atLeastOnce']).'</>',
            'count' => Format::integer($chances['any']['expectedWins']),
        ]));
        $io->writeln('  '.$this->t('budget.jackpot_chance', [
            'chance' => '<fg=#ffc83d;options=bold>'.Ui::chance($chances['tiers'][0]['atLeastOnce']).'</>',
            'odds' => Format::integer(1 / $chances['tiers'][0]['probabilityPerGrid']),
        ]));
        $io->newLine();

        $rows = [];
        foreach ($chances['tiers'] as $tier) {
            $rows[] = [
                '<options=bold>'.$tier['rank'].'</>',
                str_repeat('●', $tier['main']).str_repeat('○', 5 - $tier['main']).'  '.str_repeat('<fg=#ffc83d>★</>', $tier['bonus']).str_repeat('<fg=gray>☆</>', 2 - $tier['bonus']),
                Format::money($tier['averagePrize'], 0),
                Ui::chance($tier['atLeastOnce']),
                $tier['expectedWins'] >= 0.1 ? Format::decimal($tier['expectedWins'], $tier['expectedWins'] < 10 ? 1 : 0) : '< '.Format::decimal(0.1, 1),
            ];
        }
        $io->table([
            $this->t('budget.col_tier'),
            $this->t('budget.col_match'),
            $this->t('budget.col_prize'),
            $this->t('budget.col_at_least_once', ['years' => $years]),
            $this->t('budget.col_times'),
        ], $rows);

        $io->section($this->t('budget.back_title', ['count' => Format::integer($simulations)]));
        $io->writeln('  '.$this->t('budget.back_line', [
            'staked' => '<options=bold>'.Format::money($result['totalCost'], 0).'</>',
            'back' => '<options=bold>'.Format::money($back['median'], 0).'</>',
            'loss' => '<fg=#ff5f5f;options=bold>'.Format::money($result['totalCost'] - $back['median'], 0).'</>',
        ]));
        $io->writeln('  '.$this->t('budget.back_range', [
            'low' => '<options=bold>'.Format::money($back['p5'], 0).'</>',
            'high' => '<options=bold>'.Format::money($back['p95'], 0).'</>',
        ]));
        $io->writeln('  '.$this->t('budget.profit_lives', ['percent' => '<options=bold>'.Format::decimal(100 * $result['probabilityPositive'], 2).' %</>']));
        $io->newLine();

        Ui::note($output, $this->t('budget.average_note', ['percent' => Format::integer(100 * $result['expectedPrizePerGrid'] / $price)]));

        return Command::SUCCESS;
    }
}
