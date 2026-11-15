<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Application\Screening\ScreeningAnswerStore;
use JobSearch\Domain\Applicant\ScreeningAnswerMemory;
use JobSearch\Domain\Applicant\ScreeningFillResult;
use JobSearch\Domain\Applicant\ScreeningTask;
use JobSearch\Infrastructure\Cache\Cache;
use JobSearch\Infrastructure\Config\Config;
use JobSearch\Infrastructure\Telegram\TelegramDmClient;
use Playwright\Page\PageInterface;

/**
 * Оркестратор анкеты: learned → common rules → TG → fill.
 */
final class ScreeningFormFiller
{
    public function __construct(
        private readonly HhScreeningForm $form,
        private readonly CommonScreeningRules $rules,
        private readonly ?ScreeningAnswerMemory $memory = null,
        private readonly ?TelegramScreeningSolver $telegram = null,
    ) {}

    public static function fromConfig(
        Config $config,
        string $projectRoot,
        Cache $cache,
        ?TelegramDmClient $dm = null,
    ): self {
        $form = new HhScreeningForm();
        $answersPath = rtrim($projectRoot, '/') . '/config/screening/common_answers.json';
        $localSeed = rtrim($projectRoot, '/') . '/config/screening/learned_seed.json';
        $rules = CommonScreeningRules::fromJsonFile($answersPath);
        $memory = new ScreeningAnswerStore($cache, ScreeningAnswerStore::DEFAULT_SEED, $localSeed);
        $tg = TelegramScreeningSolver::fromConfig($config, $form, $memory, $dm);

        return new self($form, $rules, $memory, $tg);
    }

    public function form(): HhScreeningForm
    {
        return $this->form;
    }

    /**
     * Заполнить анкету на текущей странице vacancy_response.
     */
    public function fill(
        PageInterface $page,
        string $vacancyId = '',
        string $title = '',
    ): ScreeningFillResult {
        if (!$this->form->pageHasTasks($page)) {
            return ScreeningFillResult::filled(0);
        }

        $tasks = $this->form->collectTasks($page);
        if ($tasks === []) {
            return ScreeningFillResult::questionsRequired(
                'HH требует ответы на вопросы работодателя — не разобрал поля анкеты.',
            );
        }

        $filled = 0;
        /** @var list<ScreeningTask> $unknown */
        $unknown = [];

        foreach ($tasks as $task) {
            $memorized = $this->memory?->find($task->question);
            if ($memorized !== null && $memorized !== '' && $this->applyKnownAnswer($page, $task, $memorized)) {
                $filled++;
                continue;
            }
            if ($this->rules->tryFill($page, $this->form, $task)) {
                $filled++;
                continue;
            }
            $unknown[] = $task;
        }

        if ($unknown === []) {
            return ScreeningFillResult::filled($filled);
        }

        if ($this->telegram === null) {
            return ScreeningFillResult::questionsRequired(
                'HH требует ответы на вопросы работодателя — в v1 не заполняю'
                    . ' (неизвестных: ' . count($unknown)
                    . '). Включи TELEGRAM_DM_* / HH_SCREENING_TG.',
                $filled,
                count($unknown),
            );
        }

        $tg = $this->telegram->askAndFill($page, $unknown, $vacancyId, $title);
        if (!empty($tg['skip'])) {
            return ScreeningFillResult::skipped();
        }
        if (!empty($tg['timeout'])) {
            return ScreeningFillResult::tgTimeout();
        }
        if (!($tg['ok'] ?? false)) {
            return ScreeningFillResult::questionsRequired(
                'HH требует ответы на вопросы работодателя — '
                    . trim((string) ($tg['error'] ?? 'TG не принял ответы')) . '.',
                $filled + (int) ($tg['filled'] ?? 0),
                count($unknown),
            );
        }

        return ScreeningFillResult::filled($filled + (int) ($tg['filled'] ?? 0));
    }

    /** Применить learned/TG-ответ: клик по варианту + опциональное пояснение. */
    private function applyKnownAnswer(PageInterface $page, ScreeningTask $task, string $memorized): bool
    {
        $ans = trim($memorized);
        if ($ans === '') {
            return false;
        }

        $bestLabel = '';
        $bestLen = 0;
        foreach ($task->options as $opt) {
            $lab = trim($opt->label);
            if ($lab === '') {
                continue;
            }
            if (mb_stripos($ans, $lab) === 0 && mb_strlen($lab) > $bestLen) {
                $bestLabel = $lab;
                $bestLen = mb_strlen($lab);
            }
        }
        if ($bestLabel !== '') {
            if (!$this->form->applyAnswer($page, $task, $bestLabel)) {
                return false;
            }
            $rest = trim(mb_substr($ans, $bestLen));
            $rest = preg_replace('/^[:.\-–—]+\s*/u', '', $rest) ?? $rest;
            if ($rest !== '') {
                $this->form->fillText($page, $task->name, $rest);
            }

            return true;
        }

        $choice = $ans;
        $extra = '';
        if (preg_match('/^([a-zа-яё])\s*[:\-]\s*(.+)$/ui', $ans, $m) === 1) {
            $choice = $m[1];
            $extra = trim($m[2]);
        }
        $text = TelegramScreeningSolver::resolveOptionAnswer($choice, $task);
        if (!$this->form->applyAnswer($page, $task, $text)) {
            return false;
        }
        if ($extra !== '') {
            $this->form->fillText($page, $task->name, $extra);
        }

        return true;
    }
}
