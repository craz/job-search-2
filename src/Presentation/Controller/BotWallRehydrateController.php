<?php
declare(strict_types=1);

namespace JobSearch\Presentation\Controller;

use JobSearch\Application\Vacancy\ListBotWallRehydrate;
use JobSearch\Application\Vacancy\RehydrateBotWallVacancies;

/** Presentation: очередь и job перекачки bot-wall. */
final class BotWallRehydrateController
{
    public function __construct(
        private readonly ListBotWallRehydrate $list,
        private readonly ?RehydrateBotWallVacancies $rehydrate = null,
    ) {}

    /** @return array<string, mixed> */
    public function page(): array
    {
        return $this->list->page();
    }

    /**
     * @param callable|null $onProgress fn(int $index, int $total, string $externalId, string $message): void
     *
     * @return array<string, mixed>
     */
    public function run(string $storageStatePath, ?callable $onProgress = null): array
    {
        if ($this->rehydrate === null) {
            return [
                'ok' => false,
                'message' => 'Rehydrate use case не подключён.',
                'total' => 0,
                'ok_count' => 0,
                'captcha' => 0,
                'failed' => 0,
                'left' => 0,
            ];
        }

        return $this->rehydrate->run($storageStatePath, $onProgress);
    }
}
