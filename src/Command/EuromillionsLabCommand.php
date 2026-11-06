<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Lottery\StrategyLab;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:euromillions:lab',
    description: 'Strategy lab: replay a strategy over the whole history against 10,000 random players.',
)]
final class EuromillionsLabCommand extends Command
{
    public function __construct(
        private readonly DrawRepository $repository,
        private readonly StrategyLab $lab,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('strategy', InputArgument::OPTIONAL, 'Strategy: '.implode(', ', StrategyLab::STRATEGIES), null)
            ->addOption('players', null, InputOption::VALUE_REQUIRED, 'Size of the random player cohort', '10000')
            ->addOption('price', null, InputOption::VALUE_REQUIRED, 'Price of one grid in euros', '2.5')
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Learning draws before starting to play', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $strategy = $input->getArgument('strategy');
        if (null === $strategy) {
            $strategy = $io->choice('Which strategy do you want to test?', StrategyLab::STRATEGIES, 'hot');
        }
        if (!\is_string($strategy) || !\in_array($strategy, StrategyLab::STRATEGIES, true)) {
            $io->error(\sprintf('Unknown strategy. Choices: %s.', implode(', ', StrategyLab::STRATEGIES)));

            return Command::INVALID;
        }

        $players = max(100, min(100_000, InputValue::int($input->getOption('players'))));
        $price = InputValue::float($input->getOption('price'));
        $warmup = max(10, InputValue::int($input->getOption('warmup')));

        if ($price <= 0 || $price > 100) {
            $io->error('The grid price must be between 0 and 100 €.');

            return Command::INVALID;
        }

        try {
            $draws = $this->repository->all();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->title('Strategy lab — '.StrategyLab::STRATEGY_LABELS[$strategy]);
        $io->text(\sprintf(
            'Replay over <info>%d draws</info> (after %d learning draws), one grid at %.2f € per draw, '.
            'against <info>%s random players</info> simulated over the same period.',
            \count($draws) - $warmup,
            $warmup,
            $price,
            Format::integer($players),
        ));
        $io->newLine();

        $result = $this->lab->run($strategy, $draws, $players, $price, $warmup);

        $io->section('Strategy result');
        $io->table(['', '€'], [
            ['Total stakes', Format::money(-$result['cost'])],
            ['Total winnings', Format::money($result['winnings'])],
            ['<options=bold>Net result</>', '<options=bold>'.Format::money($result['net']).'</>'],
        ]);

        if ([] !== $result['winsByRank']) {
            $rows = [];
            foreach ($result['winsByRank'] as $rank => $count) {
                $rows[] = [\sprintf('Tier %d', $rank), $count];
            }
            $io->table(['Prize tier', 'Times won'], $rows);
        } else {
            $io->text('No winnings over the whole period.');
        }

        $io->section(\sprintf('Against the %s random players', Format::integer($players)));
        $io->table(['', 'Net result'], [
            ['Worst random player', Format::money($result['cohort']['worst'])],
            ['5th percentile', Format::money($result['cohort']['p5'])],
            ['Median', Format::money($result['cohort']['median'])],
            ['95th percentile', Format::money($result['cohort']['p95'])],
            ['Best random player', Format::money($result['cohort']['best'])],
        ]);

        $io->text(\sprintf(
            'The <info>%s</info> strategy finishes ahead of <info>%d</info> random players out of %s, '.
            'i.e. at the <info>%.1fth percentile</info> of the distribution.',
            $strategy,
            $result['beatenPlayers'],
            Format::integer($players),
            $result['percentile'],
        ));

        $io->note(
            'Honest reading: whatever its rank in the distribution, a strategy based on past '.
            'frequencies has no predictive power — every draw is independent. If it finishes '.
            'high (or low), that is variance, not method. And virtually every player, '.
            'strategy or not, ends up at a loss.'
        );

        return Command::SUCCESS;
    }
}
