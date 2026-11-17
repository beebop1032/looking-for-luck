<?php

declare(strict_types=1);

namespace App\Command;

use App\Intl\Format;
use App\Lottery\Lottery;
use App\Lottery\LotteryRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:sync',
    description: 'Download new draws and check saved grids, for every lottery (run hourly by cron in the Docker image).',
    aliases: ['app:euromillions:sync'],
)]
final class SyncCommand extends Command
{
    use TranslatesOutput;

    public function __construct(private readonly LotteryRegistry $registry)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('lottery', InputArgument::OPTIONAL, 'Only this lottery (slug, e.g. "euromillions")')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Download even if no new draw is expected')
            ->setHelp(<<<'HELP'
Without <info>--force</info>, a lottery is only downloaded when one of its draws took
place in the last three days and is not known yet. Running this command every
hour is therefore cheap and polite to the data sources.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('lottery');

        try {
            $lotteries = \is_string($slug) ? [$this->registry->get($slug)] : $this->registry->all();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $failed = false;
        foreach ($lotteries as $lottery) {
            $failed = !$this->sync($io, $output, $lottery, true === $input->getOption('force')) || $failed;
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    private function sync(SymfonyStyle $io, OutputInterface $output, Lottery $lottery, bool $force): bool
    {
        $synchronizer = $lottery->synchronizer();
        $now = new \DateTimeImmutable();

        if (!$force && !$synchronizer->isDue($now)) {
            $io->writeln('  <fg=gray>'.$this->t('sync.up_to_date', ['lottery' => $lottery->name(), 'date' => Format::date($lottery->nextDraw($now), 'EEE d MMM HH:mm')]).'</>');

            return true;
        }

        try {
            $result = $synchronizer->sync();
        } catch (\RuntimeException $e) {
            $io->error($this->t('sync.failed', ['lottery' => $lottery->name(), 'message' => $e->getMessage()]));

            return false;
        }

        Ui::banner($output, $this->t('sync.title', ['lottery' => $lottery->name()]), $this->t('sync.subtitle', ['draws' => Format::integer($result['draws']), 'grids' => \count($result['report'])]));

        if (null !== $result['latest']) {
            $io->writeln(\sprintf('  <fg=gray>%s</>   %s', $this->t('sync.latest', ['date' => Format::date($result['latest']->date)]), Ui::grid($result['latest']->grid)));
        }
        $io->writeln('  <fg=gray>'.$this->t('sync.next', ['date' => Format::date($lottery->nextDraw($now), 'EEEE d MMMM y, HH:mm')]).'</>');
        $io->newLine();

        foreach ($result['wins'] as $win) {
            $io->writeln('  <fg=black;bg=#2fbf71;options=bold> 🎉 '.$this->t('sync.win', $win).' </>');
        }
        if ([] === $result['wins'] && [] !== $result['report']) {
            $io->writeln('  <fg=gray>'.$this->t('sync.no_win').'</>');
        }
        $io->newLine();

        return true;
    }
}
