<?php

declare(strict_types=1);

namespace App\Command;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Messages\TextBlock;
use App\Euromillions\DrawRepository;
use App\Euromillions\InsightsSummary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:euromillions:ask',
    description: 'Chat with an AI about your stats, grids and simulations (optional, needs an Anthropic API key).',
)]
final class EuromillionsAskCommand extends Command
{
    use TranslatesOutput;

    private const DEFAULT_MODEL = 'claude-opus-5-5';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the assistant of "Looking for Luck", an honest open-source lottery
toolkit. You discuss the user's EuroMillions statistics, saved grids and
simulations using ONLY the data summary provided below.

Hard rules:
- Never claim or imply that any number, pattern or strategy improves the odds
  of winning. Every draw is independent; if asked for predictions, explain
  kindly why that is impossible and what the data actually shows.
- Be concrete and numerate: cite the figures from the summary.
- Remind the user, when money comes up, that the expected value is negative
  and the lottery is entertainment, not investment.
- Answer in the language the user writes in.
- Keep answers short and conversational: this is a terminal chat.
PROMPT;

    public function __construct(
        private readonly DrawRepository $repository,
        private readonly InsightsSummary $insights,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('question', InputArgument::OPTIONAL, 'Your question (omit for an interactive chat)')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Claude model to use', self::DEFAULT_MODEL)
            ->setHelp(<<<'HELP'
This command is <comment>optional</comment>: the rest of the toolkit works without it.

To enable it, provide an Anthropic API key (https://console.anthropic.com/):

  <info>echo 'ANTHROPIC_API_KEY=sk-ant-...' >> .env.local</info>   (git-ignored)

The AI receives a factual summary of your data (statistics, chi-squared
verdict, prize maths, saved grids) and discusses it honestly — it will not
pretend to predict anything.
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apiKey = $this->resolveApiKey();
        if (null === $apiKey) {
            $io->info([
                $this->t('ask.disabled'),
                $this->t('ask.enable'),
                '',
                '  ANTHROPIC_API_KEY=sk-ant-...',
                '',
                $this->t('ask.get_key', ['url' => 'https://console.anthropic.com/']),
            ]);

            return Command::INVALID;
        }

        $model = InputValue::string($input->getOption('model'), self::DEFAULT_MODEL);

        try {
            $draws = $this->repository->all();
        } catch (\RuntimeException) {
            $draws = []; // the assistant still works, with prize maths only
        }

        $system = self::SYSTEM_PROMPT."\n\n".$this->insights->build($draws);
        $client = new Client(apiKey: $apiKey);

        $question = $input->getArgument('question');
        $interactive = !\is_string($question) || '' === trim($question);

        if ($interactive) {
            $io->title($this->t('ask.title'));
            Ui::warning($output);
            $io->newLine();
            $io->text($this->t('ask.model', ['model' => '<info>'.$model.'</info>', 'exit' => '<comment>exit</comment>']));
            $io->newLine();
        }

        /** @var list<array{role: 'user'|'assistant', content: string}> $messages */
        $messages = [];

        do {
            if ($interactive) {
                $question = $io->ask($this->t('ask.you'));
                if (!\is_string($question) || '' === trim($question) || 'exit' === strtolower(trim($question))) {
                    return Command::SUCCESS;
                }
            }

            $messages[] = ['role' => 'user', 'content' => trim((string) $question)];

            try {
                $response = $client->messages->create(
                    model: $model,
                    maxTokens: 16000,
                    system: $system,
                    messages: $messages,
                );
            } catch (AuthenticationException) {
                $io->error($this->t('ask.bad_key'));

                return Command::FAILURE;
            } catch (APIStatusException $e) {
                $type = $e->type;
                $io->error($this->t('ask.api_error', ['type' => null !== $type ? $type->value : '?', 'message' => $e->getMessage()]));

                return Command::FAILURE;
            } catch (APIConnectionException) {
                $io->error($this->t('ask.unreachable'));

                return Command::FAILURE;
            }

            $answer = '';
            foreach ($response->content as $block) {
                if ($block instanceof TextBlock) {
                    $answer .= $block->text;
                }
            }

            $messages[] = ['role' => 'assistant', 'content' => $answer];

            $io->newLine();
            $io->text('<info>'.$this->t('ask.ai').'</info>');
            $io->text(explode("\n", $answer));
            $io->newLine();
        } while ($interactive);

        return Command::SUCCESS;
    }

    private function resolveApiKey(): ?string
    {
        foreach ([$_SERVER['ANTHROPIC_API_KEY'] ?? null, $_ENV['ANTHROPIC_API_KEY'] ?? null, getenv('ANTHROPIC_API_KEY') ?: null] as $candidate) {
            if (\is_string($candidate) && '' !== trim($candidate)) {
                return trim($candidate);
            }
        }

        return null;
    }
}
