<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Lottery\GridGenerator;
use App\Lottery\PopularityFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:euromillions:predict',
    description: 'Generate EuroMillions grids (CSPRNG) with an anti-sharing filter.',
    aliases: ['app:euromillions:generate'],
)]
final class EuromillionsPredictCommand extends Command
{
    public function __construct(
        private readonly GridGenerator $generator,
        private readonly PopularityFilter $filter,
        private readonly DrawRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('count', InputArgument::OPTIONAL, 'Number of grids to generate', '5')
            ->addOption('no-filter', null, InputOption::VALUE_NONE, 'Disable the anti-sharing filter')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Skip downloading the history (the "already drawn" filter is then inactive)')
            ->setHelp(<<<'HELP'
Every grid is drawn through <info>random_int()</info>, PHP's cryptographically secure
random generator: all combinations are strictly equiprobable.

The anti-sharing filter rejects combinations that many players tick (birthday
dates, sequences, visual patterns, multiples, combinations already drawn).
<comment>It does not change your odds of winning</comment> — they are identical for every
grid — but if you do win a big prize, you are less likely to split it with
other players.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = InputValue::int($input->getArgument('count'));
        if ($count < 1 || $count > 100) {
            $io->error('The number of grids must be between 1 and 100.');

            return Command::INVALID;
        }

        $filter = null;
        if (true !== $input->getOption('no-filter')) {
            $history = [];
            if (true !== $input->getOption('offline')) {
                try {
                    $history = $this->repository->all();
                    $io->text(\sprintf('History loaded: <info>%d draws</info> ("already drawn" filter active).', \count($history)));
                } catch (\RuntimeException $e) {
                    $io->warning('History unavailable ('.$e->getMessage().') — the "already drawn" filter is inactive.');
                }
            }
            $filter = $this->filter->withHistory($history);
        }

        $result = $this->generator->generate($count, $filter);

        $io->title(\sprintf('%d EuroMillions grid(s) — cryptographically secure draw', $count));

        $rows = [];
        foreach ($result['grids'] as $index => $grid) {
            $rows[] = [$index + 1, $grid->formatMain(), $grid->formatBonus()];
        }
        $io->table(['#', 'Numbers', 'Stars'], $rows);

        if ([] !== $result['rejected']) {
            $io->section('Combinations rejected by the anti-sharing filter');
            arsort($result['rejected']);
            foreach ($result['rejected'] as $reason => $nb) {
                $io->text(\sprintf(' · %d × %s', $nb, $reason));
            }
        }

        $io->note(
            'This filter does not change your odds of winning: every combination remains '.
            'exactly as (im)probable as any other (1 in 139,838,160 for the jackpot). '.
            'It only reduces the risk of splitting a big prize with other players '.
            'who ticked a "popular" grid.'
        );

        return Command::SUCCESS;
    }
}
