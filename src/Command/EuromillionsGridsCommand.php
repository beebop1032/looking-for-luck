<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Euromillions\EuromillionsGame;
use App\Intl\Format;
use App\Lottery\DuplicateGridException;
use App\Lottery\GridInput;
use App\Lottery\GridStore;
use App\Lottery\PrizeTable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:euromillions:grids',
    description: 'My grids: local storage and automatic checking against new draws.',
)]
final class EuromillionsGridsCommand extends Command
{
    use TranslatesOutput;

    public function __construct(
        private readonly GridStore $store,
        private readonly DrawRepository $repository,
        private readonly PrizeTable $prizeTable,
        private readonly GridInput $gridInput,
        private readonly EuromillionsGame $game,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'list | add | remove | check', 'check')
            ->addArgument('value', InputArgument::OPTIONAL, 'For add: "1 9 23 34 48 + 3 7". For remove: the grid id.')
            ->setHelp(<<<'HELP'
  <info>grids list</info>                       Show the saved grids
  <info>grids add "1 9 23 34 48 + 3 7"</info>   Save a grid (5 numbers + 2 stars)
  <info>grids remove 2</info>                   Delete grid #2
  <info>grids check</info>                      Check every grid against the draws held
                                   since its last check
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = InputValue::string($input->getArgument('action'), 'check');
        $value = $input->getArgument('value');

        return match ($action) {
            'list' => $this->listGrids($io),
            'add' => $this->addGrid($io, \is_string($value) ? $value : null),
            'remove' => $this->removeGrid($io, \is_string($value) ? $value : null),
            'check' => $this->checkGrids($io),
            default => $this->unknownAction($io, $action),
        };
    }

    private function listGrids(SymfonyStyle $io): int
    {
        $grids = $this->store->all();
        if ([] === $grids) {
            $io->info($this->t('grids.none', ['command' => 'app:euromillions:grids add "1 9 23 34 48 + 3 7"']));

            return Command::SUCCESS;
        }

        Ui::banner($io, $this->t('grids.title', ['count' => \count($grids)]));
        foreach ($grids as $saved) {
            $io->writeln(\sprintf(
                '  <fg=gray>#%-3d</> %s   <fg=gray>%s</>',
                $saved->id,
                Ui::grid($saved->grid),
                null !== $saved->lastDrawChecked
                    ? $this->t('grids.saved_checked', ['saved' => Format::date($saved->createdAt), 'checked' => Format::date($saved->lastDrawChecked)])
                    : $this->t('grids.saved_never', ['saved' => Format::date($saved->createdAt)]),
            ));
            $io->newLine();
        }

        return Command::SUCCESS;
    }

    private function addGrid(SymfonyStyle $io, ?string $value): int
    {
        if (null === $value || '' === trim($value)) {
            $io->error($this->t('grids.expected_format', ['command' => 'app:euromillions:grids add "1 9 23 34 48 + 3 7"']));

            return Command::INVALID;
        }

        try {
            $grid = $this->gridInput->parse($value, true);
            $saved = $this->store->add($grid->main, $grid->bonus);
        } catch (DuplicateGridException $e) {
            $io->error($this->t('grids.duplicate', ['id' => $e->existingId]));

            return Command::INVALID;
        } catch (\InvalidArgumentException) {
            $io->error($this->t('grids.invalid', ['example' => GridInput::EXAMPLE]));

            return Command::INVALID;
        }

        $io->newLine();
        $io->writeln(\sprintf('  <fg=#2fbf71>✔</> %s   %s', $this->t('grids.saved', ['id' => $saved->id]), Ui::grid($saved->grid)));
        if ($saved->grid->isMultiple($this->game)) {
            $io->writeln('  <fg=gray>'.$this->t('grids.multiple', ['count' => $saved->grid->combinations($this->game)]).'</>');
        }
        $io->writeln('  <fg=gray>'.$this->t('grids.saved_hint').'</>');
        $io->newLine();

        return Command::SUCCESS;
    }

    private function removeGrid(SymfonyStyle $io, ?string $value): int
    {
        if (null === $value || !ctype_digit(trim($value))) {
            $io->error($this->t('grids.remove_usage', ['command' => 'app:euromillions:grids remove 2']));

            return Command::INVALID;
        }

        if (!$this->store->remove((int) trim($value))) {
            $io->error($this->t('grids.missing', ['id' => trim($value)]));

            return Command::FAILURE;
        }

        $io->writeln('  <fg=#2fbf71>✔</> '.$this->t('grids.deleted', ['id' => (int) trim($value)]));

        return Command::SUCCESS;
    }

    private function checkGrids(SymfonyStyle $io): int
    {
        $grids = $this->store->all();
        if ([] === $grids) {
            $io->info($this->t('grids.none_to_check', ['command' => 'app:euromillions:grids add "1 9 23 34 48 + 3 7"']));

            return Command::SUCCESS;
        }

        try {
            $draws = $this->repository->all((int) date('Y') - 1);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $draws) {
            $io->error($this->t('grids.no_history'));

            return Command::FAILURE;
        }

        $latest = $draws[array_key_last($draws)];
        Ui::banner($io, $this->t('grids.check_title'), $this->t('grids.check_subtitle'));
        $io->writeln(\sprintf('  <fg=gray>%s</>   %s', $this->t('grids.latest', ['date' => Format::date($latest->date)]), Ui::grid($latest->grid)));
        $io->newLine();

        $anyWin = false;
        foreach ($this->store->findWins($draws, $this->prizeTable) as $entry) {
            $saved = $entry['grid'];
            $io->writeln(\sprintf('  <options=bold>#%d</>  %s', $saved->id, Ui::grid($saved->grid, $latest->grid)));

            if ([] === $entry['results']) {
                $io->writeln('       <fg=gray>'.$this->t('grids.nothing').'</>');
                $io->newLine();
                continue;
            }

            foreach ($entry['results'] as $result) {
                if (null !== $result['rank']) {
                    $anyWin = true;
                }
                $io->writeln(\sprintf(
                    '       <fg=gray>%s</>  %d + %d★  %s',
                    $result['date'],
                    $result['matchedMain'],
                    $result['matchedBonus'],
                    null !== $result['rank']
                        ? '<fg=black;bg=#2fbf71;options=bold> '.$this->t('grids.won', ['rank' => $result['rank'], 'amount' => Format::money($result['amount'])]).' </>'
                            .(array_sum($result['wins']) > 1 ? ' <fg=gray>'.$this->t('grids.won_count', ['count' => array_sum($result['wins'])]).'</>' : '')
                        : '<fg=gray>'.$this->t('grids.no_prize').'</>',
                ));
            }
            $io->newLine();
        }

        $this->store->markChecked($latest);

        $io->writeln($anyWin
            ? '  <fg=#2fbf71;options=bold>🎉 '.$this->t('grids.any_win').'</>'
            : '  <fg=gray>'.$this->t('grids.no_win').'</>');
        $io->newLine();

        return Command::SUCCESS;
    }

    private function unknownAction(SymfonyStyle $io, string $action): int
    {
        $io->error($this->t('grids.unknown_action', ['action' => $action]));

        return Command::INVALID;
    }
}
