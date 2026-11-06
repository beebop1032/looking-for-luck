<?php

declare(strict_types=1);

namespace App\Command;

use App\Euromillions\DrawRepository;
use App\Euromillions\GridStore;
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
    public function __construct(
        private readonly GridStore $store,
        private readonly DrawRepository $repository,
        private readonly PrizeTable $prizeTable,
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
            $io->info('No saved grids. Add one: app:euromillions:grids add "1 9 23 34 48 + 3 7"');

            return Command::SUCCESS;
        }

        $io->title(\sprintf('My grids (%d)', \count($grids)));
        $rows = [];
        foreach ($grids as $saved) {
            $rows[] = [
                $saved->id,
                $saved->grid->formatMain(),
                $saved->grid->formatBonus(),
                $saved->createdAt->format('Y-m-d'),
                $saved->lastCheckedAt?->format('Y-m-d') ?? 'never',
            ];
        }
        $io->table(['Id', 'Numbers', 'Stars', 'Created', 'Checked'], $rows);

        return Command::SUCCESS;
    }

    private function addGrid(SymfonyStyle $io, ?string $value): int
    {
        if (null === $value || '' === trim($value)) {
            $io->error('Expected format: app:euromillions:grids add "1 9 23 34 48 + 3 7"');

            return Command::INVALID;
        }

        if (1 !== preg_match('/^\s*(\d{1,2}(?:[\s,]+\d{1,2}){4})\s*\+\s*(\d{1,2}(?:[\s,]+\d{1,2}){1})\s*$/', $value, $m)) {
            $io->error('Invalid format. Expected: 5 numbers, a "+", then 2 stars. Example: "1 9 23 34 48 + 3 7"');

            return Command::INVALID;
        }

        $numbers = array_map(intval(...), preg_split('/[\s,]+/', trim($m[1])) ?: []);
        $stars = array_map(intval(...), preg_split('/[\s,]+/', trim($m[2])) ?: []);

        try {
            $saved = $this->store->add($numbers, $stars);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $io->success(\sprintf(
            'Grid #%d saved: %s. It will be verified at every "grids check".',
            $saved->id,
            $saved->grid->format(),
        ));

        return Command::SUCCESS;
    }

    private function removeGrid(SymfonyStyle $io, ?string $value): int
    {
        if (null === $value || !ctype_digit(trim($value))) {
            $io->error('Provide the id of the grid to delete: app:euromillions:grids remove 2');

            return Command::INVALID;
        }

        if (!$this->store->remove((int) trim($value))) {
            $io->error(\sprintf('No grid with id %s.', trim($value)));

            return Command::FAILURE;
        }

        $io->success('Grid deleted.');

        return Command::SUCCESS;
    }

    private function checkGrids(SymfonyStyle $io): int
    {
        $grids = $this->store->all();
        if ([] === $grids) {
            $io->info('No grids to check. Add one: app:euromillions:grids add "1 9 23 34 48 + 3 7"');

            return Command::SUCCESS;
        }

        try {
            $draws = $this->repository->all((int) date('Y') - 1);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $draws) {
            $io->error('No draw history available.');

            return Command::FAILURE;
        }

        $latest = $draws[array_key_last($draws)];
        $io->title('Checking my grids');
        $io->text(\sprintf(
            'Latest known draw: <info>%s</info> — %s',
            $latest->date->format('d/m/Y'),
            $latest->grid->format(),
        ));
        $io->newLine();

        $anyWin = false;
        foreach ($this->store->findWins($draws, $this->prizeTable) as $entry) {
            $saved = $entry['grid'];
            $label = \sprintf('Grid #%d (%s)', $saved->id, $saved->grid->format());

            if ([] === $entry['results']) {
                $io->text(\sprintf('%s: nothing noteworthy since the last check.', $label));
                continue;
            }

            $io->section($label);
            $rows = [];
            foreach ($entry['results'] as $result) {
                if (null !== $result['rank']) {
                    $anyWin = true;
                }
                $rows[] = [
                    $result['date'],
                    \sprintf('%d number(s) + %d star(s)', $result['matchedMain'], $result['matchedBonus']),
                    null !== $result['rank']
                        ? \sprintf('<info>WON — tier %d (≈ %s)</info>', $result['rank'], Format::money($this->prizeTable->prizeFor($result['rank'])))
                        : 'lost',
                ];
            }
            $io->table(['Draw', 'Matches', 'Result'], $rows);
        }

        $this->store->markChecked();

        $io->newLine();
        $io->text($anyWin
            ? '<info>At least one win detected: verify the exact amount on your lottery\'s official website.</info>'
            : 'No winnings over the checked period.');

        return Command::SUCCESS;
    }

    private function unknownAction(SymfonyStyle $io, string $action): int
    {
        $io->error(\sprintf('Unknown action "%s". Actions: list, add, remove, check.', $action));

        return Command::INVALID;
    }
}
