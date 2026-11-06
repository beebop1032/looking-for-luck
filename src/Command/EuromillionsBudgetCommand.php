<?php

declare(strict_types=1);

namespace App\Command;

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
    public function __construct(
        private readonly BudgetSimulator $simulator,
        private readonly PrizeTable $prizeTable,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('years', null, InputOption::VALUE_REQUIRED, 'Number of simulated years of play', '10')
            ->addOption('price', null, InputOption::VALUE_REQUIRED, 'Price of one grid in euros', '2.5')
            ->addOption('grids-per-draw', null, InputOption::VALUE_REQUIRED, 'Grids played at each draw (2 draws/week)', '1')
            ->addOption('simulations', null, InputOption::VALUE_REQUIRED, 'Number of Monte Carlo trajectories', '10000')
            ->addOption('jackpot', null, InputOption::VALUE_REQUIRED, 'Jackpot amount used for tier 1 (€)', '50000000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $years = InputValue::int($input->getOption('years'));
        $price = InputValue::float($input->getOption('price'));
        $gridsPerDraw = InputValue::int($input->getOption('grids-per-draw'));
        $simulations = max(1_000, min(200_000, InputValue::int($input->getOption('simulations'))));
        $jackpot = InputValue::float($input->getOption('jackpot'));

        $table = $this->prizeTable->withJackpot($jackpot);

        try {
            $result = $this->simulator->simulate($years, $gridsPerDraw, $price, $simulations, $table);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->title(\sprintf(
            'Budget simulator — %d year(s), %d grid(s) per draw at %.2f €',
            $years,
            $gridsPerDraw,
            $price,
        ));

        $io->section('Probabilities and average prizes per tier');
        $rows = [];
        foreach ($table->ranks() as $rank) {
            $rows[] = [
                $rank['rank'],
                \sprintf('%d + %d', $rank['main'], $rank['bonus']),
                '1 / '.number_format($rank['odds'], $rank['odds'] < 100 ? 1 : 0),
                Format::money($rank['averagePrize']),
            ];
        }
        $io->table(['Tier', 'Matched numbers + stars', 'Probability', 'Indicative average prize'], $rows);
        $io->text(\sprintf(
            'Probability of winning anything at one draw: <info>1 / %.1f</info>. '.
            'Expected prize per grid: <info>%s</info> for %s staked.',
            1 / $table->anyWinProbability(),
            Format::money($result['expectedPrizePerGrid']),
            Format::money($price),
        ));

        $io->section(\sprintf('Monte Carlo — %s trajectories of %d year(s)', Format::integer($simulations), $years));
        $io->table(['', 'Value'], [
            ['Grids played', Format::integer($result['gridsPlayed'])],
            ['Total stakes', Format::money($result['totalCost'])],
            ['<options=bold>Expected result (analytic)</>', '<options=bold>'.Format::money($result['expectedNet']).'</>'],
            ['Worst trajectory', Format::money($result['worst'])],
            ['5th percentile', Format::money($result['percentiles']['p5'])],
            ['25th percentile', Format::money($result['percentiles']['p25'])],
            ['Median', Format::money($result['median'])],
            ['75th percentile', Format::money($result['percentiles']['p75'])],
            ['95th percentile', Format::money($result['percentiles']['p95'])],
            ['Best trajectory', Format::money($result['best'])],
            ['Trajectories ending in profit', \sprintf('%.2f %%', 100 * $result['probabilityPositive'])],
        ]);

        $io->note(\sprintf(
            'In plain language: over %d year(s), you stake %s and should expect to lose about %s '.
            '(half of the trajectories lose more than %s). With the indicative average prizes '.
            'used here, about %.0f %% of every euro staked comes back as prizes: '.
            'it is entertainment, not an investment.',
            $years,
            Format::money($result['totalCost']),
            Format::money(abs($result['expectedNet'])),
            Format::money(abs($result['median'])),
            100 * $result['expectedPrizePerGrid'] / $price,
        ));

        return Command::SUCCESS;
    }
}
