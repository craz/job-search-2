<?php
declare(strict_types=1);

namespace JobSearch\Application\Vacancy;

use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;

/**
 * Ручной вердикт (кнопка Skip и т.п.) → новый assessment в Core.
 */
final class SetManualVerdict
{
    private const SOURCE = 'job_search_2_manual';

    public function __construct(private readonly CoreApi $core) {}

    /**
     * @return array{ok: bool, message: string, verdict: string, vacancy_id: string}
     */
    public function set(string $vacancyId, string $verdict, string $reason = ''): array
    {
        $vacancyId = trim($vacancyId);
        $verdict = strtolower(trim($verdict));
        if ($vacancyId === '') {
            return [
                'ok' => false,
                'message' => 'Укажите vacancy.',
                'verdict' => '',
                'vacancy_id' => '',
            ];
        }
        if (!in_array($verdict, ['skip', 'maybe', 'apply'], true)) {
            return [
                'ok' => false,
                'message' => 'Вердикт: skip | maybe | apply.',
                'verdict' => $verdict,
                'vacancy_id' => $vacancyId,
            ];
        }
        $reason = trim($reason);
        if ($reason === '') {
            $reason = match ($verdict) {
                'skip' => 'Ручной skip',
                'maybe' => 'Ручной возврат в Смотрим',
                'apply' => 'Ручной apply',
            };
        }

        $externalId = 'js2:manual:' . $vacancyId . ':' . $verdict . ':' . gmdate('YmdHis');
        $payload = [
            'vacancy_id' => $vacancyId,
            'source' => self::SOURCE,
            'external_id' => $externalId,
            'relevance_score' => match ($verdict) {
                'apply' => 80,
                'skip' => 20,
                default => 50,
            },
            'verdict' => $verdict,
            'reason' => $reason,
            'risk' => null,
            'action' => $verdict === 'skip' ? 'skip' : 'review',
            'model' => 'manual',
            'prompt_version' => 'manual-v1',
            'assessed_at' => gmdate('c'),
            'detail' => [
                'reason' => $reason,
                'action' => $verdict === 'skip' ? 'skip' : 'review',
                'backend' => self::SOURCE,
                'via' => 'manual',
            ],
        ];

        try {
            $this->core->createAssessment($payload, $externalId);
        } catch (CoreException $e) {
            return [
                'ok' => false,
                'message' => 'Core: ' . $e->getMessage(),
                'verdict' => $verdict,
                'vacancy_id' => $vacancyId,
            ];
        }

        return [
            'ok' => true,
            'message' => match ($verdict) {
                'skip' => 'Помечено как skip.',
                'maybe' => 'Вернули в Смотрим.',
                default => 'Вердикт: ' . $verdict,
            },
            'verdict' => $verdict,
            'vacancy_id' => $vacancyId,
        ];
    }
}
