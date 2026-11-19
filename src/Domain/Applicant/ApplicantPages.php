<?php
declare(strict_types=1);

namespace JobSearch\Domain\Applicant;

use JobSearch\Domain\Browser\BrowserCity;
use JobSearch\Domain\Chat\ChatSummary;
use JobSearch\Domain\Resume\ResumeList;
use JobSearch\Domain\Resume\ResumeViewEvent;
use JobSearch\Domain\Vacancy\VacancyCard;
use JobSearch\Domain\Vacancy\VacancyDetail;
use Playwright\Page\PageInterface;

/**
 * Порт чтения UI кабинета соискателя hh.ru (реализация — Playwright).
 */
interface ApplicantPages
{
    public const RESUMES_URL = 'https://hh.ru/applicant/resumes';
    public const LOGIN_URL = 'https://hh.ru/account/login';
    public const HOME_URL = 'https://hh.ru/';
    public const VACANCY_SERP_BASE = 'https://hh.ru/search/vacancy';
    public const CHATS_URL = 'https://hh.ru/chat';
    public const CHATIK_CHATS_URL = 'https://chatik.hh.ru/chatik/api/chats?filterUnread=false&filterHasTextMessage=false';
    public const CHATIK_LEAVE_URL = 'https://chatik.hh.ru/chatik/api/leave';
    public const RESUME_VIEW_HISTORY_URL = 'https://hh.ru/applicant/resumeview/history';

    public function suitableSerpUrl(string $resumeId, int $page = 0): string;

    /**
     * До $limit новых карточек suitable-выдачи (пропуск уже известных, листает page=0…).
     *
     * @param list<string> $excludeExternalIds
     *
     * @return list<\JobSearch\Domain\Vacancy\VacancyCard>
     */
    public function readSuitableSerp(
        PageInterface $page,
        string $resumeId,
        int $limit = 10,
        array $excludeExternalIds = [],
        int $maxPages = 12,
    ): array;

    /**
     * Карточки одной страницы suitable-SERP (без фильтра «уже скачано»).
     *
     * @return array{
     *   items: list<\JobSearch\Domain\Vacancy\VacancyCard>,
     *   total: int|null
     * }
     */
    public function readSuitableSerpPage(PageInterface $page, string $resumeId, int $serpPage): array;

    public function readVacancyDetail(PageInterface $page, VacancyCard $card): VacancyDetail;

    public function readResumeTxt(PageInterface $page, string $externalId, string $title): string;

    public function readResumes(PageInterface $page): ResumeList;

    public function looksLoggedIn(PageInterface $page): bool;

    public function switchCity(PageInterface $page, string $cityName): void;

    public function readCity(PageInterface $page): BrowserCity;

    /**
     * Отклик на вакансию с выбранным резюме (без сопроводительного письма).
     * Предусловие: страница уже в browser-контексте с storageState.
     */
    public function applyToVacancy(
        PageInterface $page,
        string $externalVacancyId,
        string $resumeId,
    ): ApplyResult;

    /**
     * Список чатов HH (карточки списка, без сообщений).
     * Предусловие: browser-контекст с storageState; метод сам открывает /chat.
     *
     * @return list<ChatSummary>
     */
    public function listChats(PageInterface $page): array;

    /**
     * Покинуть чаты (инбокс HH). Не hard-delete — POST chatik /leave.
     * Предусловие: browser-контекст с storageState; метод сам открывает /chat.
     *
     * @param list<string> $chatIds
     * @param (callable(int, int, string): void)|null $onProgress index, total, chatId
     *
     * @return array{left: int, failed: int, errors: list<string>}
     */
    public function leaveChats(PageInterface $page, array $chatIds, ?callable $onProgress = null): array;

    /**
     * История просмотров резюме (employer id + время; без vacancy_id).
     * Предусловие: browser-контекст с storageState; метод сам открывает history URL.
     *
     * @return list<ResumeViewEvent>
     */
    public function listResumeViews(PageInterface $page, string $resumeHash): array;
}
