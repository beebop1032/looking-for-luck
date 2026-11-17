<?php

declare(strict_types=1);

namespace App\Lottery;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remembers the outcome of the last sync (scheduled or manual) so the web
 * interface can show when the data was last refreshed.
 */
final class SyncStatus
{
    public function __construct(
        #[Autowire('%app.data_dir%')]
        private readonly string $dataDir,
    ) {
    }

    /**
     * @param list<string> $wins human-readable new wins, if any
     */
    public function record(bool $ok, string $message, ?string $latestDraw, array $wins = []): void
    {
        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0775, true) && !is_dir($this->dataDir)) {
            return;
        }

        $file = $this->file();
        $payload = json_encode([
            'at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'ok' => $ok,
            'message' => $message,
            'latestDraw' => $latestDraw,
            'wins' => $wins,
        ], \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT);

        if (false !== @file_put_contents($file.'.tmp', $payload)) {
            @rename($file.'.tmp', $file);
        }
    }

    /**
     * @return array{at: \DateTimeImmutable, ok: bool, message: string, latestDraw: ?string, wins: list<string>}|null
     */
    public function last(): ?array
    {
        $raw = @file_get_contents($this->file());
        if (false === $raw) {
            return null;
        }

        try {
            $data = json_decode($raw, true, 4, \JSON_THROW_ON_ERROR);
            if (!\is_array($data) || !\is_string($data['at'] ?? null)) {
                return null;
            }
            $at = new \DateTimeImmutable($data['at']);
        } catch (\Throwable) {
            return null;
        }

        $wins = [];
        foreach (\is_array($data['wins'] ?? null) ? $data['wins'] : [] as $win) {
            if (\is_string($win)) {
                $wins[] = $win;
            }
        }

        return [
            'at' => $at,
            'ok' => true === ($data['ok'] ?? false),
            'message' => \is_string($data['message'] ?? null) ? $data['message'] : '',
            'latestDraw' => \is_string($data['latestDraw'] ?? null) ? $data['latestDraw'] : null,
            'wins' => $wins,
        ];
    }

    private function file(): string
    {
        return $this->dataDir.'/last-sync.json';
    }
}
