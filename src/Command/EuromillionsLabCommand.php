<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Intl\Format;
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
    use TranslatesOutput;

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
            ->addOption('warmup', null, InputOption::VALUE_REQUIRED, 'Minimum earlier draws to learn from before playing (only matters for a lottery without rule change)', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $strategy = $input->getArgument('strategy');
        if (null === $strategy) {
            $strategy = $io->choice($this->t('lab.choose'), StrategyLab::STRATEGIES, 'hot');
        }
        if (!\is_string($strategy) || !\in_array($strategy, StrategyLab::STRATEGIES, true)) {
            $io->error($this->t('lab.unknown_strategy', ['choices' => implode(', ', StrategyLab::STRATEGIES)]));

            return Command::INVALID;
        }

        $players = max(100, min(100_000, InputValue::int($input->getOption('players'))));
        $price = InputValue::float($input->getOption('price'));
        $warmup = max(10, InputValue::int($input->getOption('warmup')));

        if ($price <= 0 || $price > 100) {
            $io->error($this->t('lab.price_range'));

            return Command::INVALID;
        }

        try {
            $draws = $this->repository->all();
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $result = $this->lab->run($strategy, $draws, $players, $price, $warmup);
        } catch (\InvalidArgumentException) {
            $io->error($this->t('lab.not_enough_history'));

            return Command::INVALID;
        }

        Ui::banner($output, $this->t('lab.title', ['strategy' => $this->t('lab.strategy_'.$strategy)]), $this->t('lab.subtitle', [
            'count' => Format::integer($result['playedDraws']),
            'from' => Format::date($result['from']),
            'to' => Format::date($result['to']),
            'price' => Format::money($price),
            'players' => Format::integer($players),
        ]));

        $io->writeln('  <fg=gray>'.$this->t('lab.desc_'.$strategy).'</>');
        $io->newLine();
        $io->writeln(\sprintf('  %-16s %s', $this->t('lab.staked'), Format::money($result['cost'], 0)));
        $io->writeln(\sprintf('  %-16s %s', $this->t('lab.won'), Format::money($result['winnings'], 0)));
        $io->writeln(\sprintf('  <options=bold>%-16s %s</>', $this->t('lab.net'), Ui::money($result['net'], true)));
        $io->newLine();

        if ([] !== $result['winsByRank']) {
            $max = max($result['winsByRank']);
            foreach ($result['winsByRank'] as $rank => $count) {
                $io->writeln(\sprintf('  %-9s %s <options=bold>%d</>×', $this->t('lab.tier', ['rank' => $rank]), Ui::bar($count / $max, 24, '#ffc83d'), $count));
            }
        } else {
            $io->writeln('  <fg=gray>'.$this->t('lab.no_wins').'</>');
        }

        $io->newLine();
        $io->writeln('  <fg=gray>'.$this->t('lab.distinct', ['distinct' => Format::integer($result['distinctGrids']), 'count' => Format::integer($result['playedDraws'])]).'</>');
        if ('random' !== $strategy) {
            $io->writeln(\sprintf('  %s   %s', $this->t('lab.next_pick'), Ui::grid($result['nextGrid'])));
        }

        $io->section($this->t('lab.against', ['count' => Format::integer($players)]));
        $io->writeln('  '.$this->t('lab.random_losses', [
            'median' => Format::money(-$result['cohort']['median'], 0),
            'low' => Format::money(-$result['cohort']['p95'], 0),
            'high' => Format::money(-$result['cohort']['p5'], 0),
        ]));
        $io->newLine();
        $io->writeln(\sprintf('  %s  %s  %s', $this->t('lab.worse'), Ui::gauge($result['percentile']), $this->t('lab.better')));
        $io->writeln('  <options=bold>'.$this->t('lab.percentile', ['value' => Format::integer($result['percentile'])]).'</>');
        $io->writeln('  <fg=gray>'.$this->t('lab.ordinary', ['percent' => Format::integer(100 - $result['percentile'])]).'</>');
        $io->newLine();

        Ui::note($output, $this->t('random' === $strategy ? 'lab.random_note' : 'lab.honest'));

        return Command::SUCCESS;
    }
}
