<?php
declare(strict_types=1);

namespace JobSearch\Application\Scoring;

/**
 * EXCLUDE-01/02: управление РОПами и лидоген/партнёрская сеть как primary duty.
 * Порт детерминированных regex из job_search_ref (без Python runtime).
 */
final class CommercialDutyExclusions
{
    private const SALES_LEADERSHIP_TITLE = '~(?:'
        . 'руководител\w*\s+отдела\s+продаж|'
        . 'директор\s+по\s+продажам|'
        . 'коммерческ\w*\s+директор|'
        . 'head\s+of\s+sales|'
        . 'sales\s+director|'
        . 'chief\s+revenue|'
        . '\bcro\b|'
        . 'руководитель\s+продаж\b|'
        . 'начальник\s+отдела\s+продаж'
        . ')~iu';

    private const ROP_MANAGEMENT_DUTY = '~(?:'
        . 'управлен\w*\s+руководител\w*\s+(?:отделов?\s+)?продаж|'
        . 'руковод\w*\s+руководител\w*\s+(?:отделов?\s+)?продаж|'
        . 'управлен\w*\s+роп(?:ами|ов|а)?\b|'
        . 'руковод\w*\s+роп(?:ами|ов|а)?\b|'
        . 'в\s+прямо(?:м|е)\s+подчинении[^\n.]{0,100}(?:роп|руководител\w*\s+отдел\w*\s+продаж)|'
        . '(?:роп|руководител\w*\s+отделов?\s+продаж)[^\n.]{0,60}в\s+подчинении|'
        . 'подчинен\w*[^\n.]{0,40}(?:роп|руководител\w*\s+отдел\w*\s+продаж)|'
        . 'непосредственн\w*\s+управлен\w*[^\n.]{0,40}продаж|'
        . 'управлен\w*\s+(?:отделом|командой)\s+продаж|'
        . 'manage(?:ment)?\s+of\s+sales\s+managers|'
        . 'managing\s+sales\s+managers|'
        . 'sales\s+managers?\s+in\s+(?:direct\s+)?report'
        . ')~iu';

    private const OBLIGATION_CUE = '~(?:'
        . 'обязанност|требуется|обязательн|must\s+have|you\s+will|'
        . 'ключев\w*\s+задач|основн\w*\s+(?:обязан|задач|функционал)|'
        . 'в\s+ваши\s+задачи|будет\s+входить|зона\s+ответственност'
        . ')~iu';

    private const ROP_AMBIGUOUS = '~(?:'
        . 'управлен\w*\s+продажами|развит\w*\s+отдела?\s+продаж|'
        . 'руковод\w*\s+продаж|\bроп\b|sales\s+management'
        . ')~iu';

    private const ROP_EXEMPT = '~(?:'
        . 'взаимодейств\w*[^\n.]{0,40}(?:отделом\s+)?продаж|'
        . 'координац\w*[^\n.]{0,40}(?:отделом\s+)?продаж|'
        . 'совместн\w*[^\n.]{0,40}(?:отделом\s+)?продаж|'
        . 'сотрудничеств\w*[^\n.]{0,40}(?:отделом\s+)?продаж|'
        . 'стейкхолдер\w*[^\n.]{0,50}продаж|заказчик\w*[^\n.]{0,50}продаж|'
        . 'work(?:ing)?\s+with\s+(?:the\s+)?sales|collaborate\s+with\s+sales|'
        . 'разработк\w*[^\n.]{0,40}crm|внедрен\w*[^\n.]{0,40}crm|delivery\s+(?:of\s+)?crm|'
        . 'управлен\w*\s+(?:техническ|продуктов|проектн)\w*\s+команд|'
        . 'управлен\w*\s+командой\s+разработ'
        . ')~iu';

    private const LEADGEN_PRIMARY_DUTY = '~(?:'
        . 'самостоятельн\w*\s+лидогенерац|'
        . 'лидогенерац\w*[^\n.]{0,50}самостоятельн|'
        . '(?:обязанност|требуется|ключев\w*\s+задач|основн\w*\s+обязан)[^\n.]{0,80}лидогенерац|'
        . 'лидогенерац\w*[^\n.]{0,40}(?:обязан|требуется|входит\s+в)|'
        . 'активн\w*\s+(?:поиск|привлечен\w*)\s+(?:новых\s+)?клиент|'
        . 'привлечен\w*\s+новых\s+клиент|'
        . 'поиск\s+и\s+привлечен\w*\s+(?:новых\s+)?клиент|'
        . 'коммерческ\w*\s+развит\w*\s+партн[её]рск|'
        . 'развит\w*\s+партн[её]рск\w*\s+сет|'
        . 'построен\w*\s+партн[её]рск\w*\s+сет|'
        . 'расширен\w*\s+партн[её]рск\w*\s+сет|'
        // Фандрайзинг / BD-партнёрства (не IT-delivery): поиск компаний и развитие партнёрств.
        . 'фандрайзинг|'
        . 'fundraising|'
        . 'привлечен\w*\s+средств|'
        . 'развит\w*\s+партн[её]рств|'
        . 'развит\w*\s+партн[её]рск\w*\s+инициатив|'
        . 'выстраива\w*\s+нов\w*\s+партн[её]рств|'
        . 'поиск\w*[^\n.]{0,60}партн[её]рств|'
        . 'корпоративн\w*\s+(?:фандрайзинг|партн[её]рств)|'
        . 'corporate\s+partnerships?|'
        . 'lead\s+generation|generate\s+leads|hunt(?:ing)?\s+new\s+clients|'
        . 'business\s+development\s+(?:manager|lead|director)|\bbdm\b'
        . ')~iu';

    private const LEADGEN_AMBIGUOUS = '~(?:'
        . 'лидогенерац|лидоген|привлечен\w*\s+клиент|'
        . 'партн[её]рск\w*\s+сет|развит\w*\s+партн[её]р|'
        . 'фандрайзинг|fundraising|привлечен\w*\s+средств|'
        . 'new\s+business|business\s+development'
        . ')~iu';

    private const LEADGEN_EXEMPT = '~(?:'
        . 'существующ\w*\s+(?:b2b[-\s]?)?клиент|текущ\w*\s+(?:b2b[-\s]?)?клиент|'
        . 'сопровожден\w*\s+(?:клиент|заказчик)|account\s+management|'
        . 'координац\w*\s+подрядчик|управлен\w*\s+подрядчик|'
        . 'управлен\w*\s+crm|внедрен\w*[^\n.]{0,40}crm|разработк\w*[^\n.]{0,40}crm|'
        . 'процесс\w*\s+продаж|воронк\w*\s+продаж|автоматизац\w*[^\n.]{0,40}продаж|'
        . '(?:будет\s+)?плюсом|желательн\w*|nice\s+to\s+have|optional|необязательн|'
        . 'контекст\w*\s+проект|в\s+рамках\s+проект'
        . ')~iu';

    private const DELIVERY_OR_TECH_TITLE = '~(?:'
        . 'delivery\s+manager|project\s+manager|техническ\w*\s+проект|'
        . 'руководитель\s+проект|product\s+manager|scrum\s+master|'
        . 'программн\w*\s+менеджер|руководитель\s+разработ'
        . ')~iu';

    /**
     * @param array{title?: string, description?: string} $vacancy
     */
    public function assessSalesManagersLeadership(array $vacancy): CommercialDutyHit
    {
        [$title, , $blob] = $this->blob($vacancy);
        $code = 'sales_managers_leadership';

        if (preg_match(self::SALES_LEADERSHIP_TITLE, $title, $m) === 1) {
            $snip = $this->snippet(self::SALES_LEADERSHIP_TITLE, $title) ?: trim($title);

            return new CommercialDutyHit(
                true,
                false,
                $code,
                $snip,
                'EXCLUDE-01: title is sales leadership — managing sales organisation is primary. Evidence: «' . $snip . '»',
            );
        }

        if (preg_match(self::ROP_MANAGEMENT_DUTY, $blob, $m, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $m[0][1];
            if (
                preg_match(self::ROP_EXEMPT, $blob) === 1
                && preg_match(self::DELIVERY_OR_TECH_TITLE, $title) === 1
                && !$this->nearObligation($blob, $offset)
            ) {
                $snip = $this->snippet(self::ROP_MANAGEMENT_DUTY, $blob);

                return new CommercialDutyHit(
                    false,
                    true,
                    $code,
                    $snip,
                    'EXCLUDE-01 ambiguous: ROP wording with delivery title — manual review. Evidence: «' . $snip . '»',
                );
            }
            if ($this->nearObligation($blob, $offset) || preg_match(self::SALES_LEADERSHIP_TITLE, $blob) === 1) {
                $snip = $this->snippet(self::ROP_MANAGEMENT_DUTY, $blob);

                return new CommercialDutyHit(
                    true,
                    false,
                    $code,
                    $snip,
                    'EXCLUDE-01: required/primary duty to manage sales managers (ROPs). Evidence: «' . $snip . '»',
                );
            }
            $snip = $this->snippet(self::ROP_MANAGEMENT_DUTY, $blob);

            return new CommercialDutyHit(
                false,
                true,
                $code,
                $snip,
                'EXCLUDE-01 ambiguous: sales-managers wording without clear obligation. Evidence: «' . $snip . '»',
            );
        }

        if (preg_match(self::ROP_AMBIGUOUS, $blob) === 1 && preg_match(self::ROP_EXEMPT, $blob) !== 1) {
            $snip = $this->snippet(self::ROP_AMBIGUOUS, $blob);

            return new CommercialDutyHit(
                false,
                true,
                $code,
                $snip,
                'EXCLUDE-01 ambiguous: sales-management mention. Evidence: «' . $snip . '»',
            );
        }

        return new CommercialDutyHit(false, false, $code, '', 'EXCLUDE-01: no sales-managers leadership signal.');
    }

    /**
     * @param array{title?: string, description?: string} $vacancy
     */
    public function assessLeadgenPartnershipsPrimary(array $vacancy): CommercialDutyHit
    {
        [$title, , $blob] = $this->blob($vacancy);
        $code = 'leadgen_partnerships_primary';

        if (preg_match(self::LEADGEN_EXEMPT, $blob) === 1 && preg_match(self::LEADGEN_PRIMARY_DUTY, $blob) !== 1) {
            return new CommercialDutyHit(
                false,
                false,
                $code,
                '',
                'EXCLUDE-02: exempt context without primary duty.',
            );
        }

        if (preg_match(self::LEADGEN_PRIMARY_DUTY, $blob, $m, PREG_OFFSET_CAPTURE) === 1) {
            $offset = (int) $m[0][1];
            $matchText = (string) $m[0][0];
            if (
                preg_match(self::LEADGEN_EXEMPT, $blob) === 1
                && preg_match(self::DELIVERY_OR_TECH_TITLE, $title) === 1
            ) {
                $personal = preg_match(
                    '~самостоятельн\w*\s+лидогенерац|активн\w*\s+(?:поиск|привлечен)|lead\s+generation|hunt(?:ing)?\s+new\s+clients~iu',
                    $matchText,
                ) === 1;
                if ($this->nearObligation($blob, $offset) && $personal) {
                    $snip = $this->snippet(self::LEADGEN_PRIMARY_DUTY, $blob);

                    return new CommercialDutyHit(
                        true,
                        false,
                        $code,
                        $snip,
                        'EXCLUDE-02: required personal leadgen/new-client hunting. Evidence: «' . $snip . '»',
                    );
                }
                $snip = $this->snippet(self::LEADGEN_PRIMARY_DUTY, $blob);

                return new CommercialDutyHit(
                    false,
                    false,
                    $code,
                    $snip,
                    'EXCLUDE-02 not applied: delivery/tech title with exempt context. Evidence: «' . $snip . '»',
                );
            }

            if ($this->nearObligation($blob, $offset) || preg_match(self::DELIVERY_OR_TECH_TITLE, $title) !== 1) {
                $snip = $this->snippet(self::LEADGEN_PRIMARY_DUTY, $blob);

                return new CommercialDutyHit(
                    true,
                    false,
                    $code,
                    $snip,
                    'EXCLUDE-02: leadgen / partner-network growth is required or primary. Evidence: «' . $snip . '»',
                );
            }
            $snip = $this->snippet(self::LEADGEN_PRIMARY_DUTY, $blob);

            return new CommercialDutyHit(
                false,
                true,
                $code,
                $snip,
                'EXCLUDE-02 ambiguous under delivery title. Evidence: «' . $snip . '»',
            );
        }

        if (preg_match(self::LEADGEN_AMBIGUOUS, $blob) === 1 && preg_match(self::LEADGEN_EXEMPT, $blob) !== 1) {
            $snip = $this->snippet(self::LEADGEN_AMBIGUOUS, $blob);

            return new CommercialDutyHit(
                false,
                true,
                $code,
                $snip,
                'EXCLUDE-02 ambiguous: commercial growth wording. Evidence: «' . $snip . '»',
            );
        }

        return new CommercialDutyHit(false, false, $code, '', 'EXCLUDE-02: no leadgen/partnerships primary signal.');
    }

    /**
     * Первый hard-skip среди EXCLUDE-01/02 или null.
     *
     * @param array{title?: string, description?: string} $vacancy
     */
    public function firstHardSkip(array $vacancy): ?CommercialDutyHit
    {
        $e01 = $this->assessSalesManagersLeadership($vacancy);
        if ($e01->hardSkip) {
            return $e01;
        }
        $e02 = $this->assessLeadgenPartnershipsPrimary($vacancy);
        if ($e02->hardSkip) {
            return $e02;
        }

        return null;
    }

    /**
     * @param array{title?: string, description?: string} $vacancy
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function blob(array $vacancy): array
    {
        $title = trim((string) ($vacancy['title'] ?? ''));
        $description = trim((string) ($vacancy['description'] ?? ''));

        return [$title, $description, $title . "\n" . $description];
    }

    private function snippet(string $pattern, string $text, int $radius = 55): string
    {
        if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $start = max(0, (int) $m[0][1] - $radius);
        $end = min(strlen($text), (int) $m[0][1] + strlen((string) $m[0][0]) + $radius);
        $chunk = substr($text, $start, $end - $start);

        return trim(preg_replace('/\s+/u', ' ', $chunk) ?? $chunk);
    }

    private function nearObligation(string $text, int $matchOffset, int $window = 120): bool
    {
        $start = max(0, $matchOffset - $window);
        $chunk = substr($text, $start, $window * 2);

        return preg_match(self::OBLIGATION_CUE, $chunk) === 1;
    }
}
