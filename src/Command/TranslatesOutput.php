<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Contracts\Service\Attribute\Required;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Console strings live in translations/cli+intl-icu.<locale>.yaml.
 */
trait TranslatesOutput
{
    private TranslatorInterface $translator;

    #[Required]
    public function setTranslator(TranslatorInterface $translator): void
    {
        $this->translator = $translator;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function t(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'cli');
    }

    /** Anti-sharing filter reasons are translated from their English text. */
    private function reason(string $reason): string
    {
        return $this->translator->trans($reason, [], 'filter');
    }
}
