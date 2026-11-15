<?php
declare(strict_types=1);

namespace JobSearch\Infrastructure\Applicant;

use JobSearch\Domain\Applicant\ScreeningTask;
use Playwright\Page\PageInterface;

/**
 * Типовые ответы на анкеты HH (порт resume/scripts/hh_screening_fill.py).
 * Неизвестный вопрос → false (fail-closed).
 */
final class CommonScreeningRules
{
    /** @var array<string, string> */
    private array $a;

    /** @param array<string, string> $answers */
    public function __construct(array $answers)
    {
        $this->a = $answers;
    }

    public static function fromJsonFile(string $path): self
    {
        $raw = is_readable($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $answers = [];
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (is_string($k) && is_string($v) && trim($v) !== '') {
                    $answers[$k] = $v;
                }
            }
        }

        return new self($answers);
    }

    /** Заполнить известный вопрос. true = обработан; false = unknown. */
    public function tryFill(PageInterface $page, HhScreeningForm $form, ScreeningTask $task): bool
    {
        $q = mb_strtolower($task->question);
        $name = $task->name;
        $opts = $task->options;
        $hasYn = $task->hasYesNo();

        if (preg_match('/forms\.gle|ссылка\s+на\s+анкет|заполн\w*\s+анкет|google\s*form|тип[ао]form/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, false) : false;
        }

        if (preg_match('/тк\s*рф|официальн\w*\s*оформлен|трудов\w*\s*договор|оформлен\w*\s*по\s*тк/ui', $q) === 1) {
            return $form->yesNo($page, $task, true);
        }

        if (preg_match('/зарплат|доход|ожидан|вилк|финансов|уровень\s*доход|сколько\s*хотите|оклад/ui', $q) === 1) {
            $pay = preg_match('/\bgross\b|до\s*вычет|до\s*ндфл/ui', $q) === 1
                ? $this->t('salary_gross')
                : $this->t('salary_net');

            return $form->fillText($page, $name, $pay);
        }

        if (preg_match('/город.*формат|формат.*город|проживаете.*формат|формат.*прожива/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('city_format'));
        }

        if (preg_match('/город\s+ваш|в\s+каком\s+городе|город\s+прожива|где\s+прожива/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('city'));
        }

        if (preg_match('/из\s+какой\s+страны|страны?\s+вы\s+планируете\s+работать|страна\s+работ|work\s+from\s+which\s+country/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('country_work'));
        }

        if (preg_match('/из\s+какой\s+локац|локац\w*\s+планируете\s+работать|какой\s+локац|from\s+which\s+location/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('city') . ', ' . $this->t('country_work'));
        }

        if (preg_match('/формат\s+работ|предпочтительн\w*\s+формат|офис.*гибрид.*удал/ui', $q) === 1) {
            if ($opts !== [] && !$hasYn) {
                if ($form->clickLabel($page, $name, $opts, 'удал|remote|свой\\s+вариант')) {
                    $form->fillText($page, $name, $this->t('format_free'));

                    return true;
                }

                return false;
            }

            return $form->fillText($page, $name, $this->t('format_free'));
        }

        if (preg_match(
            '/готов\w*\s+.*офис|офис\s*5\s*\/\s*2|только\s+офис|офисн\w*\s+формат|работать\s+из\s+офис|ленинск\w*\s+проспект|причальн|3\s+месяц.*офис|рассматриваешь\s+для\s+себя\s+такой\s+формат/ui',
            $q,
        ) === 1) {
            if (preg_match('/причальн|москв/ui', $q) === 1 && $opts !== [] && !$hasYn) {
                if ($form->clickLabel($page, $name, $opts, 'свой\\s+вариант')) {
                    return $form->fillText($page, $name, $this->t('office_relo_msk'));
                }
                if ($form->fillText($page, $name, $this->t('office_relo_msk'))) {
                    return true;
                }

                return false;
            }
            if ($hasYn) {
                return $form->yesNo($page, $task, true);
            }

            return $form->fillText($page, $name, $this->t('office_relo_generic'));
        }

        if (preg_match('/крупн\w*\s+техническ\w*\s+программ|само[йе]\s+крупн\w*\s+техническ/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('tpm_largest'));
        }

        if (preg_match('/capacity|resource\s*planning|загрузк\w*\s+.*команд/ui', $q) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, true)
                : $form->fillText($page, $name, $this->t('tpm_capacity'));
        }

        if (preg_match('/техническ\w*\s+зависимост|критическ\w*\s+пут/ui', $q) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, true)
                : $form->fillText($page, $name, $this->t('tpm_deps'));
        }

        if (preg_match(
            '/глубок\w*\s+.*техническ\w*\s+проработ|архитектура.*системн\w*\s+анализ|backend\/frontend\/mobile|погружал\w*\s+в\s+техническ/ui',
            $q,
        ) === 1) {
            return $form->fillText($page, $name, $this->t('tpm_depth'));
        }

        if (preg_match('/\bcto\b|vp\s*it|c-level|управленческ\w*\s+отч[её]т/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('tpm_clevel'));
        }

        if (preg_match('/telegram|телеграм|\btg\b|ник\s+в\s+tg/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('telegram'));
        }

        if (preg_match('/английск|english|устн\w*\s+коммуникац/ui', $q) === 1) {
            if (preg_match('/уровень|level|\bb1\b|\bb2\b/ui', $q) === 1
                && preg_match('/устн|заказчик/ui', $q) !== 1) {
                return $form->fillText($page, $name, $this->t('english_level'));
            }
            if ($hasYn) {
                if ($form->yesNo($page, $task, false)) {
                    return true;
                }
                if ($form->clickLabel($page, $name, $opts, 'свой\\s+вариант')) {
                    return $form->fillText($page, $name, $this->t('english_free'));
                }

                return false;
            }

            return $form->fillText($page, $name, $this->t('english_free'));
        }

        if (preg_match('/работаете\s+ли\s+вы\s+сейчас|работаешь\s+ли\s+ты\s+сейчас|текущ\w*\s+занят/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('employment'));
        }

        if (preg_match('/прямо\w*\s+подчинен|скольк\w*\s+человек.*подчинен|численность.*команд/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('headcount'));
        }

        if (preg_match('/удал[её]нн\w*\s+команд/ui', $q) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, true)
                : $form->fillText($page, $name, $this->t('remote_teams'));
        }

        if (preg_match('/44[-\s]?фз|223[-\s]?фз/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, false) : $form->fillText($page, $name, 'Нет');
        }

        if (preg_match('/iso\s*27001|gdpr/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, false) : $form->fillText($page, $name, 'Нет');
        }

        if (preg_match('/руководител\w*\s+команд\s+продаж|управлен\w*\s+роп|роп(?:ами|ов)?\b/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, false) : $form->fillText($page, $name, 'Нет');
        }

        if (preg_match('/реинжиниринг|операционн\w*\s+эффективн/ui', $q) === 1) {
            if ($hasYn && preg_match('/расскажите|подробнее|индустр/ui', $q) !== 1) {
                return $form->yesNo($page, $task, true);
            }

            return $form->fillText($page, $name, $this->t('reeng'));
        }

        if (preg_match('/\bb2c\b|в\s+сфере\s+b2c/ui', $q) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, true)
                : $form->fillText($page, $name, $this->t('b2c'));
        }

        if (preg_match('/enterprise|внешн\w*\s+коммерческ|b2b\s*клиент/ui', $q) === 1) {
            if ($form->fillText($page, $name, $this->t('b2b'))) {
                return true;
            }

            return $hasYn ? $form->yesNo($page, $task, true) : false;
        }

        if (preg_match('/запуск\w*\s+it|веден\w*\s+техническ\w*\s+проект|опыт\s+запуск/ui', $q) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, true)
                : $form->fillText($page, $name, $this->t('launch'));
        }

        if (preg_match('/инфраструктурн|сервер|баз\w*\s+данных|ит-архитектур|it-архитектур/ui', $q) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, true)
                : $form->fillText($page, $name, $this->t('infra'));
        }

        if (preg_match('/dwh|data\s*lake|хран[ие]лищ\w*\s+данных|оз[её]р\w*\s+данных/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, false) : $form->fillText($page, $name, 'Нет');
        }

        if (preg_match('/hadoop|spark|greenplum|clickhouse|big\s*data|kafka/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('bigdata'));
        }

        if (preg_match('/автоматизац\w*\s+производ|бизнес-аналитик|функциональн\w*\s+тестир/ui', $q) === 1) {
            if (preg_match('/адаптац|готов\w*\s+.*тестир|готов\w*\s+.*аналитик/ui', $q) === 1) {
                return $hasYn
                    ? $form->yesNo($page, $task, true)
                    : $form->fillText($page, $name, $this->t('ba_adapt'));
            }

            return $form->fillText($page, $name, $this->t('ba_prod'));
        }

        if (preg_match('/лидогенерац|привлечен\w*\s+клиент|партн[её]рск\w*\s+сет/ui', $q) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, false)
                : $form->fillText($page, $name, $this->t('leadgen'));
        }

        if (preg_match('/\bp&l\b|выручк|прибыл|\bkpi\b|коммерческ\w*\s+kpi|метрик\w*.*ответ/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('kpi'));
        }

        if (preg_match('/как\s+.*(?:ии|ai|искусственн)|используете\s+ии|используешь\s+ии/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('ai'));
        }

        if (preg_match('/провал|неудачн|очевидн\w*\s+провал/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('failure'));
        }

        if (preg_match('/руками|продолжает\w*\s+делать/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('hands'));
        }

        if (preg_match('/ищет\w*\s+в\s+новой|чем\s+интересн|что\s+ищет|ожидан\w*\s+от\s+новой/ui', $q) === 1) {
            $txt = preg_match('/ожидан/ui', $q) === 1 ? $this->t('expect') : $this->t('looking');

            return $form->fillText($page, $name, $txt);
        }

        if (preg_match('/проектн\w*\s+офис|ai-проект|ai\s+проект/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('pmo_ai'));
        }

        if (preg_match('/финтех|fintech|банковск|доменн\w*\s+област/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, false) : $form->fillText($page, $name, 'Нет');
        }

        if (preg_match(
            '/техническ\w*\s+опыт|опыт\s+работы\s+тестировщик|системн\w*\s+аналитик|разработчик\w*\s+в\s+it|опыт.*разработчик/ui',
            $q,
        ) === 1) {
            return $hasYn
                ? $form->yesNo($page, $task, true)
                : $form->fillText($page, $name, $this->t('tech_exp'));
        }

        if (preg_match('/где\s+вы\s+сейчас\s+прожива|где\s+сейчас\s+прожива|где\s+проживаете/ui', $q) === 1) {
            return $form->fillText($page, $name, $this->t('city'));
        }

        if (preg_match('/международн\w*\s+ип|ип\s*\(.*вне\s+росси|открытое\s+.*ип|ип\s+вне\s+росси/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, false) : $form->fillText($page, $name, 'Нет');
        }

        if (preg_match('/it[-\s]?аккредит|аккредитац/ui', $q) === 1) {
            if ($opts !== []) {
                if ($form->clickLabel($page, $name, $opts, 'не\\s+принципиальн')) {
                    return true;
                }
                if ($form->clickLabel($page, $name, $opts, 'свой\\s+вариант')) {
                    return $form->fillText($page, $name, $this->t('it_accreditation'));
                }

                return false;
            }

            return $form->fillText($page, $name, $this->t('it_accreditation'));
        }

        if (preg_match('/подтверждаю.*достоверн|сведения\s+являются\s+достоверн|настоящим\s+подтверждаю/ui', $q) === 1) {
            return $hasYn ? $form->yesNo($page, $task, true) : $form->fillText($page, $name, 'Да');
        }

        return false;
    }

    private function t(string $key): string
    {
        return $this->a[$key] ?? '';
    }
}
