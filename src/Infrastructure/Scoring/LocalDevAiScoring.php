<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Scoring;

use JobSearch\Application\Scoring\CommercialDutyExclusions;
use JobSearch\Application\Scoring\CommercialPrimaryGuard;
use JobSearch\Application\Scoring\DevAiVerdictParser;
use JobSearch\Application\Scoring\ScorePromptRenderer;
use JobSearch\Application\Scoring\ScoringProfile;
use JobSearch\Domain\Core\CoreApi;
use JobSearch\Domain\Core\CoreException;
use JobSearch\Domain\DevAi\DevAi;
use JobSearch\Domain\DevAi\DevAiException;
use JobSearch\Domain\Scoring\ScoringApi;
use JobSearch\Domain\Scoring\ScoringException;
use JobSearch\Domain\Vacancy\HhBotWall;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\DevAi\CurlDevAiClient;

/**
 * Локальный scoring: pre-gate EXCLUDE → DevAI prompt → Core assessment.
 * Заменяет Python SCORING_API_URL в потоке ScoreTen.
 */
final class LocalDevAiScoring implements ScoringApi
{
    public const SOURCE = 'job-search-2-devai';

    public function __construct(
        private readonly CoreApi $core,
        private readonly DevAi $devAi,
        private readonly ScorePromptRenderer $prompts,
        private readonly ScoringProfile $profile,
        private readonly CommercialDutyExclusions $exclusions,
        private readonly CommercialPrimaryGuard $commercialGuard,
        private readonly DevAiVerdictParser $parser,
        private readonly string $model,
    ) {}

    public static function fromConfig(
        Config $config,
        CoreApi $core,
        string $projectRoot,
        ?DevAi $devAi = null,
    ): self {
        $profile = ScoringProfile::load($projectRoot);
        $model = trim((string) ($config->get('DEVAI_MODEL', 'devai/auto') ?? 'devai/auto'));

        return new self(
            $core,
            $devAi ?? CurlDevAiClient::fromConfig($config),
            ScorePromptRenderer::fromProjectRoot($projectRoot, $profile),
            $profile,
            new CommercialDutyExclusions(),
            new CommercialPrimaryGuard(),
            new DevAiVerdictParser(),
            $model !== '' ? $model : 'devai/auto',
        );
    }

    public function enqueueSemanticBulk(array $vacancyIds, string $source = 'devai_auto'): array
    {
        $ids = [];
        $seen = [];
        foreach ($vacancyIds as $raw) {
            $id = trim((string) $raw);
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }
        if ($ids === []) {
            throw new ScoringException('empty_vacancy_ids');
        }

        $force = in_array($source, ['devai_force', 'force_rescore'], true);
        $enqueued = 0;
        $failed = 0;
        $alreadyScored = 0;
        $skipped = [];
        $results = [];

        foreach ($ids as $vacancyId) {
            try {
                if (!$force && $this->hasAssessment($vacancyId)) {
                    $alreadyScored++;
                    $results[] = ['vacancy_id' => $vacancyId, 'status' => 'already_scored'];
                    continue;
                }
                $outcome = $this->scoreOne($vacancyId, $force);
                $enqueued++;
                $results[] = ['vacancy_id' => $vacancyId, 'status' => 'scored', 'verdict' => $outcome['verdict']];
            } catch (ScoringException $e) {
                $failed++;
                $skipped[] = ['vacancy_id' => $vacancyId, 'code' => 'score_failed', 'message' => $e->getMessage()];
                $results[] = ['vacancy_id' => $vacancyId, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return [
            'enqueued' => $enqueued,
            'failed' => $failed,
            'already_queued' => 0,
            'already_scored' => $alreadyScored,
            'skipped' => $skipped,
            'results' => $results,
            'source' => $source !== '' ? $source : 'devai_auto',
            'backend' => self::SOURCE,
        ];
    }

    /**
     * @return array{verdict: string, reason: string, action: string, decisive_factors: list<string>}
     */
    private function scoreOne(string $vacancyId, bool $force = false): array
    {
        try {
            $vacancy = $this->core->getVacancy($vacancyId);
        } catch (CoreException $e) {
            throw new ScoringException('Core getVacancy: ' . $e->getMessage(), 0, $e);
        }

        $material = [
            'title' => (string) ($vacancy['title'] ?? ''),
            'description' => (string) ($vacancy['description'] ?? ''),
        ];

        if (HhBotWall::looksLike($material['title'], $material['description'])) {
            $parsed = [
                'verdict' => 'skip',
                'reason' => 'Страница HH «не робот»/капча вместо вакансии — в базу попал мусор, не оценивать.',
                'action' => 'Пропустить вакансию',
                'decisive_factors' => ['FETCH.hh_bot_wall'],
            ];
            $this->writeAssessment($vacancyId, $vacancy, $parsed, 'pregate', $force);

            return $parsed;
        }

        if ($this->profile->hardSkipSalesManagersLeadership() || $this->profile->hardSkipLeadgenPartnershipsPrimary()) {
            $hit = $this->exclusions->firstHardSkip($material);
            if ($hit !== null && $hit->hardSkip) {
                $factor = $hit->code === 'sales_managers_leadership'
                    ? 'DESIRABILITY.sales_managers_leadership.skip'
                    : 'DESIRABILITY.leadgen_partnerships_primary.skip';
                $parsed = [
                    'verdict' => 'skip',
                    'reason' => $hit->reason,
                    'action' => 'Пропустить вакансию',
                    'decisive_factors' => [$factor],
                ];
                $this->writeAssessment($vacancyId, $vacancy, $parsed, 'pregate', $force);

                return $parsed;
            }
        }

        $resumeContext = $this->resumeContext();
        try {
            $prompt = $this->prompts->render($vacancy, $resumeContext);
            $chat = $this->devAi->chatCompletions(
                $this->model,
                [
                    ['role' => 'system', 'content' => 'You are a job vacancy scoring assistant. Reply with JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            );
            $parsed = $this->parser->parse($chat);
            $parsed = $this->commercialGuard->apply($parsed, $material);
        } catch (DevAiException $e) {
            throw new ScoringException('DevAI: ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            throw new ScoringException('score_build: ' . $e->getMessage(), 0, $e);
        }

        $this->writeAssessment($vacancyId, $vacancy, $parsed, 'devai', $force);

        return $parsed;
    }

    /**
     * @param array<string, mixed> $vacancy
     * @param array{verdict: string, reason: string, action: string, decisive_factors: list<string>} $parsed
     */
    private function writeAssessment(
        string $vacancyId,
        array $vacancy,
        array $parsed,
        string $via,
        bool $force = false,
    ): void {
        $externalId = 'js2:' . $via . ':' . $vacancyId . ':' . substr(sha1(
            $parsed['verdict'] . '|' . $parsed['reason'] . '|' . ($vacancy['content_hash'] ?? '')
            . ($force ? '|force:' . gmdate('YmdHis') : ''),
        ), 0, 16);
        $payload = [
            'vacancy_id' => $vacancyId,
            'source' => self::SOURCE,
            'external_id' => $externalId,
            'relevance_score' => match ($parsed['verdict']) {
                'apply' => 80,
                'skip' => 20,
                default => 50,
            },
            'verdict' => $parsed['verdict'],
            'reason' => $parsed['reason'],
            'risk' => null,
            'action' => $parsed['action'],
            'model' => $this->model,
            'prompt_version' => $this->profile->promptVersion,
            'assessed_at' => gmdate('c'),
            'detail' => [
                'reason' => $parsed['reason'],
                'action' => $parsed['action'],
                'deterministic_signals' => $parsed['decisive_factors'],
                'backend' => self::SOURCE,
                'via' => $via,
            ],
        ];

        try {
            $this->core->createAssessment($payload, $externalId);
        } catch (CoreException $e) {
            throw new ScoringException('Core createAssessment: ' . $e->getMessage(), 0, $e);
        }
    }

    /** @return array<string, mixed> */
    private function resumeContext(): array
    {
        try {
            $ctx = $this->core->getCandidateContext();
        } catch (CoreException) {
            return [];
        }
        $resume = is_array($ctx['resume_version'] ?? null) ? $ctx['resume_version'] : [];
        $content = is_array($resume['content'] ?? null) ? $resume['content'] : [];
        $title = trim((string) ($content['title'] ?? $resume['title'] ?? ''));
        $external = trim((string) ($resume['external_id'] ?? $ctx['hh_resume_external_id'] ?? ''));
        $text = '';
        if (isset($content['text']) && is_string($content['text'])) {
            $text = $content['text'];
        } elseif (isset($content['summary']) && is_string($content['summary'])) {
            $text = $content['summary'];
        } else {
            $text = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
        }

        return [
            'title' => $title,
            'external_id' => $external,
            'text' => $text,
            'raw' => $ctx,
        ];
    }

    private function hasAssessment(string $vacancyId): bool
    {
        try {
            $list = $this->core->listAssessments($vacancyId, 1);
        } catch (CoreException) {
            return false;
        }

        return $list['total'] > 0 || $list['items'] !== [];
    }
}
