<?php

declare(strict_types=1);

namespace App\Lottery;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every lottery the application supports (one LotteryDefinition each), plus
 * the ones on the roadmap that still need a contributor.
 */
final class LotteryRegistry
{
    /** @var array<string, Lottery> */
    private array $lotteries = [];

    /**
     * @param iterable<LotteryDefinition> $definitions
     * @param list<array{name: string, region: string, issue: int}> $planned
     */
    public function __construct(
        #[AutowireIterator('app.lottery')]
        iterable $definitions,
        #[Autowire('%app.data_root%')]
        string $dataRoot,
        #[Autowire('%app.planned_lotteries%')]
        private readonly array $planned = [],
        ?GridStorageFactory $gridStorages = null,
    ) {
        foreach ($definitions as $definition) {
            $slug = $definition->slug();
            if (1 !== preg_match('/^[a-z0-9-]+$/', $slug)) {
                throw new \LogicException(\sprintf('Invalid lottery slug "%s": use lowercase letters, digits and dashes.', $slug));
            }
            if (isset($this->lotteries[$slug])) {
                throw new \LogicException(\sprintf('Two lotteries use the slug "%s".', $slug));
            }
            $this->lotteries[$slug] = new Lottery($definition, rtrim($dataRoot, '/').'/'.$slug, $gridStorages);
        }
    }

    /**
     * @return list<Lottery>
     */
    public function all(): array
    {
        return array_values($this->lotteries);
    }

    public function find(string $slug): ?Lottery
    {
        return $this->lotteries[$slug] ?? null;
    }

    public function get(string $slug): Lottery
    {
        return $this->find($slug) ?? throw new \InvalidArgumentException(\sprintf('Unknown lottery "%s". Known: %s.', $slug, implode(', ', array_keys($this->lotteries))));
    }

    /**
     * Lotteries on the roadmap that are not supported yet, with the GitHub
     * issue where contributors can pick them up.
     *
     * @return list<array{name: string, region: string, issue: int}>
     */
    public function planned(): array
    {
        $supported = array_map(static fn (Lottery $l): string => strtolower($l->name()), $this->lotteries);

        return array_values(array_filter(
            $this->planned,
            static fn (array $p): bool => !\in_array(strtolower($p['name']), $supported, true),
        ));
    }
}
