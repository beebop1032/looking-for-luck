<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Intl\Format;
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
    use TranslatesOutput;

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

Output language: --locale=fr (or LFL_LOCALE / LC_ALL); en, fr, nl, es, de.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = InputValue::int($input->getArgument('count'));
        if ($count < 1 || $count > 100) {
            $io->error($this->t('predict.count_range'));

            return Command::INVALID;
        }

        Ui::banner($output, $this->t('predict.title', ['count' => $count]), $this->t('predict.subtitle'));

        $filter = null;
        if (true !== $input->getOption('no-filter')) {
            $history = [];
            if (true !== $input->getOption('offline')) {
                try {
                    $history = $this->repository->all();
                    $io->text('<fg=gray>'.$this->t('predict.history_loaded', ['count' => Format::integer(\count($history))]).'</>');
                } catch (\RuntimeException $e) {
                    $io->warning($this->t('predict.history_unavailable', ['message' => $e->getMessage()]));
                }
            }
            $filter = $this->filter->withHistory($history);
        } else {
            $io->text('<fg=gray>'.$this->t('predict.filter_disabled').'</>');
        }

        $result = $this->generator->generate($count, $filter);

        $io->newLine();
        foreach ($result['grids'] as $index => $grid) {
            $io->writeln(\sprintf('  <fg=gray>%3d</>   %s', $index + 1, Ui::grid($grid)));
            $io->newLine();
        }

        if ([] !== $result['rejectedGrids']) {
            $io->section($this->t('predict.rejected_title', ['count' => $result['rejectedTotal']]));
            $io->writeln('  <fg=gray>'.$this->t('predict.rejected_text').'</>');
            $io->newLine();
            foreach (\array_slice($result['rejectedGrids'], 0, 10) as $rejected) {
                $io->writeln(\sprintf('        %s', Ui::grid($rejected['grid'])));
                foreach ($rejected['reasons'] as $reason) {
                    $io->writeln(\sprintf('        <fg=#ffc83d>↳</> <fg=gray>%s</>', $this->reason($reason)));
                }
                $io->newLine();
            }
            if ($result['rejectedTotal'] > 10) {
                $io->writeln('  <fg=gray>'.$this->t('predict.rejected_more', ['count' => $result['rejectedTotal'] - 10]).'</>');
                $io->newLine();
            }
        }

        Ui::note($output, $this->t('predict.honest', ['odds' => Format::integer(139_838_160)]));

        return Command::SUCCESS;
    }
}
