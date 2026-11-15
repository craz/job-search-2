<?php
declare(strict_types=1);

namespace JobSearch\Application\Error;

use JobSearch\Infrastructure\Cache\Cache;

/**
 * Локальный журнал ошибок UI/jobs (FileCache), без Core.
 * Одна открытая запись на пару resume+vacancy (upsert, без дублей).
 */
final class AppErrorStore
{
    public const CACHE_KEY = 'app_errors';
    private const MAX_ITEMS = 300;

    public function __construct(private readonly Cache $cache) {}

    /**
     * @return list<array{
     *   id: string,
     *   at: string,
     *   source: string,
     *   resume_id: string,
     *   resume_title: string,
     *   vacancy_id: string,
     *   vacancy_title: string,
     *   message: string,
     *   resolved_at: string
     * }>
     */
    public function all(): array
    {
        $entry = $this->cache->get(self::CACHE_KEY);
        $payload = $entry?->payload;
        if (!is_array($payload)) {
            return [];
        }
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $out = [];
        foreach ($items as $row) {
            $norm = $this->normalizeRow($row);
            if ($norm !== null) {
                $out[] = $norm;
            }
        }

        return $out;
    }

    /**
     * Схлопнуть дубли по resume+vacancy (оставить самую свежую). Вызывать с UI.
     *
     * @return int сколько лишних записей убрано
     */
    public function compactDuplicates(): int
    {
        $items = $this->all();
        $before = count($items);
        $compacted = $this->compactItems($items);
        $removed = $before - count($compacted);
        if ($removed > 0) {
            $this->cache->set(self::CACHE_KEY, ['items' => $compacted]);
        }

        return $removed;
    }

    /**
     * Пометить ошибки по паре resume+vacancy как исправленные (успешный отклик).
     *
     * @return int сколько записей обновлено
     */
    public function markApplied(string $resumeId, string $vacancyId, string $note = 'Исправлено — откликнуто.'): int
    {
        $resumeId = trim($resumeId);
        $vacancyId = trim($vacancyId);
        $note = trim($note);
        if ($resumeId === '' || $vacancyId === '' || $note === '') {
            return 0;
        }

        $items = $this->all();
        $changed = 0;
        $now = gmdate('c');
        foreach ($items as $i => $row) {
            if (($row['resolved_at'] ?? '') !== '') {
                continue;
            }
            if (($row['resume_id'] ?? '') !== $resumeId || ($row['vacancy_id'] ?? '') !== $vacancyId) {
                continue;
            }
            $prior = trim((string) ($row['message'] ?? ''));
            $message = $note;
            if ($prior !== '' && !str_starts_with($prior, 'Исправлено')) {
                $message .= "\nБыло: " . $prior;
            }
            $items[$i]['message'] = $message;
            $items[$i]['resolved_at'] = $now;
            $items[$i]['skipped_at'] = '';
            $changed++;
        }
        if ($changed === 0) {
            return 0;
        }
        $this->cache->set(self::CACHE_KEY, ['items' => $this->compactItems($items)]);

        return $changed;
    }

    /**
     * Закрыть открытые ошибки, если по vacancy уже есть чат HH.
     *
     * @param array<string, true>|list<string> $vacancyExternalIds
     *
     * @return list<array{resume_id: string, resume_title: string, vacancy_id: string}>
     */
    public function reconcileOpenAgainstVacancies(array $vacancyExternalIds, string $note = 'Исправлено — есть чат HH.'): array
    {
        $note = trim($note) !== '' ? trim($note) : 'Исправлено — есть чат HH.';
        /** @var array<string, true> $want */
        $want = [];
        $isList = array_is_list($vacancyExternalIds);
        foreach ($vacancyExternalIds as $key => $value) {
            $id = $isList ? trim((string) $value) : trim((string) $key);
            if ($id !== '') {
                $want[$id] = true;
            }
        }
        if ($want === []) {
            return [];
        }

        $items = $this->all();
        $resolved = [];
        $now = gmdate('c');
        $changed = 0;
        foreach ($items as $i => $row) {
            if (($row['resolved_at'] ?? '') !== '') {
                continue;
            }
            $vacancyId = trim((string) ($row['vacancy_id'] ?? ''));
            $resumeId = trim((string) ($row['resume_id'] ?? ''));
            if ($vacancyId === '' || $resumeId === '' || !isset($want[$vacancyId])) {
                continue;
            }
            $prior = trim((string) ($row['message'] ?? ''));
            $message = $note;
            if ($prior !== '' && !str_starts_with($prior, 'Исправлено')) {
                $message .= "\nБыло: " . $prior;
            }
            $items[$i]['message'] = $message;
            $items[$i]['resolved_at'] = $now;
            $changed++;
            $resolved[] = [
                'resume_id' => $resumeId,
                'resume_title' => trim((string) ($row['resume_title'] ?? '')),
                'vacancy_id' => $vacancyId,
            ];
        }
        if ($changed > 0) {
            $this->cache->set(self::CACHE_KEY, ['items' => $this->compactItems($items)]);
        }

        return $resolved;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Дописать vacancy_title там, где пусто (по external_id вакансии).
     *
     * @param array<string, string> $titlesByVacancyId
     *
     * @return int сколько записей обновлено
     */
    public function fillMissingVacancyTitles(array $titlesByVacancyId): int
    {
        if ($titlesByVacancyId === []) {
            return 0;
        }
        $items = $this->all();
        $changed = 0;
        foreach ($items as $i => $row) {
            if (trim((string) ($row['vacancy_title'] ?? '')) !== '') {
                continue;
            }
            $vid = trim((string) ($row['vacancy_id'] ?? ''));
            if ($vid === '') {
                continue;
            }
            $title = trim((string) ($titlesByVacancyId[$vid] ?? ''));
            if ($title === '' || $title === $vid) {
                continue;
            }
            $items[$i]['vacancy_title'] = $title;
            $changed++;
        }
        if ($changed === 0) {
            return 0;
        }
        $this->cache->set(self::CACHE_KEY, ['items' => $items]);

        return $changed;
    }

    /**
     * Открытые ошибки для UI вакансий: пара resume+vacancy → текст, счётчик по резюме.
     * Как «Открытые» на /?page=errors: без skip TG и без «вакансия недоступна»
     * (недоступные — вкладка «Недоступны», не бейдж «Ошибки» у резюме).
     *
     * @return array{
     *   by_pair: array<string, array{message: string, at: string, source: string}>,
     *   by_resume: array<string, int>,
     *   skipped_by_pair: array<string, array{message: string, at: string, source: string}>
     * }
     */
    public function openVacancyHints(): array
    {
        $byPair = [];
        $byResume = [];
        $skippedByPair = [];
        foreach ($this->all() as $row) {
            $resumeId = trim((string) ($row['resume_id'] ?? ''));
            $vacancyId = trim((string) ($row['vacancy_id'] ?? ''));
            if ($resumeId === '' || $vacancyId === '') {
                continue;
            }
            $key = $resumeId . "\0" . $vacancyId;
            $message = trim((string) ($row['message'] ?? ''));
            $hint = [
                'message' => $message,
                'at' => trim((string) ($row['at'] ?? '')),
                'source' => trim((string) ($row['source'] ?? '')),
            ];
            $isSkipped = trim((string) ($row['skipped_at'] ?? '')) !== '' || self::isSkipMessage($message);
            if ($isSkipped) {
                if (!isset($skippedByPair[$key])) {
                    $skippedByPair[$key] = $hint;
                }
                continue;
            }
            if (($row['resolved_at'] ?? '') !== '') {
                continue;
            }
            // Недоступная вакансия — не «открытая ошибка отклика» (иначе бейдж → filter=open пусто).
            if (self::isUnavailableMessage($message)) {
                continue;
            }
            if (!isset($byPair[$key])) {
                $byPair[$key] = $hint;
                $byResume[$resumeId] = ($byResume[$resumeId] ?? 0) + 1;
            }
        }

        return [
            'by_pair' => $byPair,
            'by_resume' => $byResume,
            'skipped_by_pair' => $skippedByPair,
        ];
    }

    /**
     * @param array{
     *   source?: string,
     *   resume_id?: string,
     *   resume_title?: string,
     *   vacancy_id?: string,
     *   vacancy_title?: string,
     *   message: string,
     *   at?: string
     * } $row
     */
    public function append(array $row): void
    {
        $this->appendMany([$row]);
    }

    /**
     * Upsert: при resume+vacancy обновляет существующую запись (снимает resolved),
     * иначе добавляет новую. Дубли по ключу не плодит.
     *
     * @param list<array{
     *   source?: string,
     *   resume_id?: string,
     *   resume_title?: string,
     *   vacancy_id?: string,
     *   vacancy_title?: string,
     *   message: string,
     *   at?: string
     * }> $rows
     */
    public function appendMany(array $rows): void
    {
        $items = $this->all();
        $changed = false;
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $message = trim((string) ($row['message'] ?? ''));
            if ($message === '') {
                continue;
            }
            $at = trim((string) ($row['at'] ?? ''));
            if ($at === '') {
                $at = gmdate('c');
            }
            $skipNow = self::isSkipMessage($message);
            $nowIso = gmdate('c');
            $incoming = [
                'id' => bin2hex(random_bytes(8)),
                'at' => $at,
                'source' => trim((string) ($row['source'] ?? '')),
                'resume_id' => trim((string) ($row['resume_id'] ?? '')),
                'resume_title' => trim((string) ($row['resume_title'] ?? '')),
                'vacancy_id' => trim((string) ($row['vacancy_id'] ?? '')),
                'vacancy_title' => trim((string) ($row['vacancy_title'] ?? '')),
                'message' => $message,
                'resolved_at' => $skipNow ? $nowIso : '',
                'skipped_at' => $skipNow ? $nowIso : '',
            ];
            $key = $this->pairKey($incoming['resume_id'], $incoming['vacancy_id']);
            if ($key === null) {
                array_unshift($items, $incoming);
                $changed = true;
                continue;
            }
            $idx = $this->findIndexByKey($items, $key);
            if ($idx === null) {
                array_unshift($items, $incoming);
                $changed = true;
                continue;
            }
            $prev = $items[$idx];
            $items[$idx] = [
                'id' => $prev['id'] !== '' ? $prev['id'] : $incoming['id'],
                'at' => $incoming['at'],
                'source' => $incoming['source'] !== '' ? $incoming['source'] : $prev['source'],
                'resume_id' => $incoming['resume_id'],
                'resume_title' => $incoming['resume_title'] !== '' ? $incoming['resume_title'] : $prev['resume_title'],
                'vacancy_id' => $incoming['vacancy_id'],
                'vacancy_title' => $incoming['vacancy_title'] !== '' ? $incoming['vacancy_title'] : $prev['vacancy_title'],
                'message' => $incoming['message'],
                'resolved_at' => $incoming['resolved_at'],
                'skipped_at' => $incoming['skipped_at'],
            ];
            // Поднять обновлённую запись наверх.
            $updated = $items[$idx];
            array_splice($items, $idx, 1);
            array_unshift($items, $updated);
            $changed = true;
        }
        if (!$changed) {
            return;
        }
        $items = $this->compactItems($items);
        if (count($items) > self::MAX_ITEMS) {
            $items = array_slice($items, 0, self::MAX_ITEMS);
        }
        $this->cache->set(self::CACHE_KEY, ['items' => $items]);
    }

    public function clear(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /**
     * Закрыть одну запись по id (сироты без vacancy_id — «Повторить» недоступен).
     *
     * @return bool найдена и закрыта
     */
    public function markResolvedById(string $id, string $note = 'Закрыто вручную.'): bool
    {
        $id = trim($id);
        $note = trim($note) !== '' ? trim($note) : 'Закрыто вручную.';
        if ($id === '') {
            return false;
        }
        $items = $this->all();
        $changed = false;
        $now = gmdate('c');
        foreach ($items as $i => $row) {
            if (trim((string) ($row['id'] ?? '')) !== $id) {
                continue;
            }
            if (trim((string) ($row['resolved_at'] ?? '')) !== '') {
                return true;
            }
            $prior = trim((string) ($row['message'] ?? ''));
            $message = $note;
            if ($prior !== '' && !str_starts_with($prior, 'Исправлено') && !str_starts_with($prior, 'Закрыто')) {
                $message .= "\nБыло: " . $prior;
            }
            $items[$i]['message'] = $message;
            $items[$i]['resolved_at'] = $now;
            $changed = true;
            break;
        }
        if (!$changed) {
            return false;
        }
        $this->cache->set(self::CACHE_KEY, ['items' => $this->compactItems($items)]);

        return true;
    }

    /**
     * Ручной скип из журнала ошибок (не TG) → вкладка «Скип».
     *
     * @return bool найдена и помечена
     */
    public function markSkippedById(string $id, string $note = 'Пропуск — вручную из журнала ошибок.'): bool
    {
        $id = trim($id);
        $note = trim($note) !== '' ? trim($note) : 'Пропуск — вручную из журнала ошибок.';
        if ($id === '') {
            return false;
        }
        $items = $this->all();
        $changed = false;
        $now = gmdate('c');
        foreach ($items as $i => $row) {
            if (trim((string) ($row['id'] ?? '')) !== $id) {
                continue;
            }
            if (trim((string) ($row['skipped_at'] ?? '')) !== '') {
                return true;
            }
            $prior = trim((string) ($row['message'] ?? ''));
            $message = $note;
            if ($prior !== '' && !str_starts_with($prior, 'Пропуск') && !str_starts_with($prior, 'Исправлено')) {
                $message .= "\nБыло: " . $prior;
            }
            $items[$i]['message'] = $message;
            $items[$i]['resolved_at'] = $now;
            $items[$i]['skipped_at'] = $now;
            $changed = true;
            break;
        }
        if (!$changed) {
            return false;
        }
        $this->cache->set(self::CACHE_KEY, ['items' => $this->compactItems($items)]);

        return true;
    }

    /** HH: вакансия закрыта/скрыта (не путать с протухшей browser-сессией). */
    public static function isUnavailableMessage(string $message): bool
    {
        return (bool) preg_match(
            '/вакансия недоступна|закрыта или скрыта|вам недоступна эта вакансия/iu',
            $message,
        );
    }

    /** HH: обязательно сопроводительное письмо (DevAI/TG fail, skip, timeout). */
    public static function isLetterRequiredMessage(string $message): bool
    {
        return (bool) preg_match(
            '/сопроводительн|letter_required|без письма|cover.?letter/iu',
            $message,
        );
    }

    /** HH: анкета / вопросы работодателя на /applicant/vacancy_response (v1 не заполняем). */
    public static function isQuestionsRequiredMessage(string $message): bool
    {
        return (bool) preg_match(
            '/вопрос(?:ов|ы)? работодателя|ответы на вопросы|questions_required|анкет|screening|TG-ответ не получен/iu',
            $message,
        );
    }

    /** Пропуск формы в Telegram (письмо / анкета) — не открытая ошибка. */
    public static function isSkipMessage(string $message): bool
    {
        return (bool) preg_match(
            '/пропущена?\s*\(skip|пропущено\s*\(skip|skip в TG|status=skipped|\bTG skip\b/iu',
            $message,
        );
    }

    /**
     * Закрыть ошибку как skip (форма пропущена в TG) — уходит из «Открытых» и «Ошибок» вакансий.
     *
     * @return int сколько записей обновлено
     */
    public function markSkipped(string $resumeId, string $vacancyId, string $note = ''): int
    {
        $resumeId = trim($resumeId);
        $vacancyId = trim($vacancyId);
        $note = trim($note);
        if ($note === '') {
            $note = 'Пропуск — skip в TG.';
        }
        if ($resumeId === '' || $vacancyId === '') {
            return 0;
        }

        $items = $this->all();
        $changed = 0;
        $now = gmdate('c');
        $found = false;
        foreach ($items as $i => $row) {
            if (($row['resume_id'] ?? '') !== $resumeId || ($row['vacancy_id'] ?? '') !== $vacancyId) {
                continue;
            }
            $found = true;
            $prior = trim((string) ($row['message'] ?? ''));
            $message = $note;
            if ($prior !== '' && !str_starts_with($prior, 'Пропуск') && !str_starts_with($prior, 'Исправлено')) {
                $message .= "\nБыло: " . $prior;
            }
            $items[$i]['message'] = $message;
            $items[$i]['resolved_at'] = $now;
            $items[$i]['skipped_at'] = $now;
            $changed++;
        }
        if (!$found) {
            array_unshift($items, [
                'id' => bin2hex(random_bytes(8)),
                'at' => $now,
                'source' => 'apply',
                'resume_id' => $resumeId,
                'resume_title' => '',
                'vacancy_id' => $vacancyId,
                'vacancy_title' => '',
                'message' => $note,
                'resolved_at' => $now,
                'skipped_at' => $now,
            ]);
            $changed = 1;
        }
        if ($changed === 0) {
            return 0;
        }
        $this->cache->set(self::CACHE_KEY, ['items' => $this->compactItems($items)]);

        return $changed;
    }

    /**
     * Открытые ошибки с парой resume+vacancy (для «Повторить все»), без дублей.
     *
     * @return list<array{
     *   resume_id: string,
     *   resume_title: string,
     *   vacancy_id: string,
     *   vacancy_title: string
     * }>
     */
    public function retryableTargets(): array
    {
        $seen = [];
        $out = [];
        foreach ($this->all() as $row) {
            if (($row['resolved_at'] ?? '') !== '') {
                continue;
            }
            $message = (string) ($row['message'] ?? '');
            if (self::isUnavailableMessage($message) || self::isSkipMessage($message)) {
                continue;
            }
            if (trim((string) ($row['skipped_at'] ?? '')) !== '') {
                continue;
            }
            $resumeId = trim((string) ($row['resume_id'] ?? ''));
            $vacancyId = trim((string) ($row['vacancy_id'] ?? ''));
            if ($resumeId === '' || $vacancyId === '') {
                continue;
            }
            $key = $resumeId . "\0" . $vacancyId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'resume_id' => $resumeId,
                'resume_title' => trim((string) ($row['resume_title'] ?? '')),
                'vacancy_id' => $vacancyId,
                'vacancy_title' => trim((string) ($row['vacancy_title'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * @param list<array{
     *   id: string,
     *   at: string,
     *   source: string,
     *   resume_id: string,
     *   resume_title: string,
     *   vacancy_id: string,
     *   vacancy_title: string,
     *   message: string,
     *   resolved_at: string
     * }> $items
     *
     * @return list<array{
     *   id: string,
     *   at: string,
     *   source: string,
     *   resume_id: string,
     *   resume_title: string,
     *   vacancy_id: string,
     *   vacancy_title: string,
     *   message: string,
     *   resolved_at: string
     * }>
     */
    private function compactItems(array $items): array
    {
        $best = [];
        $order = [];
        $passthrough = [];
        foreach ($items as $row) {
            $key = $this->pairKey($row['resume_id'], $row['vacancy_id']);
            if ($key === null) {
                $passthrough[] = $row;
                continue;
            }
            if (!isset($best[$key])) {
                $best[$key] = $row;
                $order[] = $key;
                continue;
            }
            $cur = $best[$key];
            // Предпочитаем более новый at; при равенстве — незакрытый / более свежий resolved.
            if ($this->isNewer($row, $cur)) {
                $best[$key] = $row;
            }
        }
        $out = [];
        foreach ($order as $key) {
            $out[] = $best[$key];
        }

        return array_merge($out, $passthrough);
    }

    /**
     * @param array{at: string, resolved_at: string, message: string} $a
     * @param array{at: string, resolved_at: string, message: string} $b
     */
    private function isNewer(array $a, array $b): bool
    {
        $atA = strtotime($a['at'] ?: '') ?: 0;
        $atB = strtotime($b['at'] ?: '') ?: 0;
        if ($atA !== $atB) {
            return $atA > $atB;
        }
        $openA = ($a['resolved_at'] ?? '') === '';
        $openB = ($b['resolved_at'] ?? '') === '';
        if ($openA !== $openB) {
            return $openA;
        }

        return true;
    }

    /**
     * @param list<array{resume_id: string, vacancy_id: string}> $items
     */
    private function findIndexByKey(array $items, string $key): ?int
    {
        foreach ($items as $i => $row) {
            $rowKey = $this->pairKey($row['resume_id'], $row['vacancy_id']);
            if ($rowKey === $key) {
                return $i;
            }
        }

        return null;
    }

    private function pairKey(string $resumeId, string $vacancyId): ?string
    {
        $resumeId = trim($resumeId);
        $vacancyId = trim($vacancyId);
        if ($resumeId === '' || $vacancyId === '') {
            return null;
        }

        return $resumeId . "\0" . $vacancyId;
    }

    /**
     * @return array{
     *   id: string,
     *   at: string,
     *   source: string,
     *   resume_id: string,
     *   resume_title: string,
     *   vacancy_id: string,
     *   vacancy_title: string,
     *   message: string,
     *   resolved_at: string
     * }|null
     */
    private function normalizeRow(mixed $row): ?array
    {
        if (!is_array($row)) {
            return null;
        }
        $message = trim((string) ($row['message'] ?? ''));
        if ($message === '') {
            return null;
        }

        return [
            'id' => trim((string) ($row['id'] ?? '')),
            'at' => trim((string) ($row['at'] ?? '')),
            'source' => trim((string) ($row['source'] ?? '')),
            'resume_id' => trim((string) ($row['resume_id'] ?? '')),
            'resume_title' => trim((string) ($row['resume_title'] ?? '')),
            'vacancy_id' => trim((string) ($row['vacancy_id'] ?? '')),
            'vacancy_title' => trim((string) ($row['vacancy_title'] ?? '')),
            'message' => $message,
            'resolved_at' => trim((string) ($row['resolved_at'] ?? '')),
            'skipped_at' => trim((string) ($row['skipped_at'] ?? '')),
        ];
    }
}
