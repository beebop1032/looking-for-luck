<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Language of the console output, opt-in: --locale=fr, then the LFL_LOCALE,
 * LC_ALL and LC_MESSAGES environment variables, else English. LANG is
 * deliberately ignored: it is set on every system, and output would switch
 * language without anyone asking for it.
 */
final class ConsoleLocaleSubscriber implements EventSubscriberInterface
{
    /**
     * @param list<string> $locales
     */
    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
        #[Autowire('%app.locales%')]
        private readonly array $locales,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConsoleEvents::COMMAND => ['onCommand', 100]];
    }

    public function onCommand(ConsoleCommandEvent $event): void
    {
        $command = $event->getCommand();
        if (null === $command || !str_starts_with((string) $command->getName(), 'app:')) {
            return;
        }

        // Added to the native definition: Command::run() rebuilds the full one from it.
        $definition = $command->getNativeDefinition();
        if (!$definition->hasOption('locale')) {
            $definition->addOption(new InputOption('locale', null, InputOption::VALUE_REQUIRED, 'Language of the output: '.implode(', ', $this->locales)));
        }

        $this->localeSwitcher->setLocale($this->resolve($event->getInput()->getParameterOption('--locale', null, true)));
    }

    public function resolve(mixed $option): string
    {
        $candidates = [$option, $_SERVER['LFL_LOCALE'] ?? getenv('LFL_LOCALE'), getenv('LC_ALL'), getenv('LC_MESSAGES')];
        foreach ($candidates as $candidate) {
            // "fr", "fr_BE.UTF-8", "de-DE"…
            if (\is_string($candidate) && 1 === preg_match('/^([a-z]{2})(?:[_-]|$)/i', $candidate, $m)) {
                $locale = strtolower($m[1]);
                if (\in_array($locale, $this->locales, true)) {
                    return $locale;
                }
            }
        }

        return $this->locales[0];
    }
}
