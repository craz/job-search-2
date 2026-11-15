<?php
declare(strict_types=1);

/**
 * Регрессии FIT.commercial_primary guard (без DevAI).
 * Run: php tests/unit/CommercialPrimaryGuardTest.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use JobSearch\Application\Scoring\CommercialDutyExclusions;
use JobSearch\Application\Scoring\CommercialPrimaryGuard;

$guard = new CommercialPrimaryGuard();
$exclusions = new CommercialDutyExclusions();
$failed = 0;

$assert = static function (bool $cond, string $msg) use (&$failed): void {
    if ($cond) {
        echo "OK  $msg\n";

        return;
    }
    $failed++;
    echo "FAIL $msg\n";
};

$fakeCommercialSkip = [
    'verdict' => 'skip',
    'reason' => 'Роль в основном связана с продажами.',
    'action' => 'Пропустить вакансию',
    'decisive_factors' => ['FIT.commercial_primary'],
];

// 1) CPO маркетплейса с GMV/P&L — нет commercial hard (demote)
$proteco = [
    'title' => 'CPO (Chief Product Officer) / Руководитель по продукту онлайн-платформы',
    'description' => 'Пилотные продажи MVP. Этап Scale & P&L: GMV, Take-Rate, CAC, LTV, юнит-экономика продукта, монетизация платформы, интеграции FBO/FBS.',
];
$out = $guard->apply($fakeCommercialSkip, $proteco);
$assert($out['verdict'] === 'maybe', 'CPO marketplace: verdict maybe');
$assert(!in_array('FIT.commercial_primary', $out['decisive_factors'], true), 'CPO marketplace: no FIT.commercial_primary');
$assert($guard->shouldDemoteCommercialSkip($proteco), 'CPO marketplace: shouldDemote');

// 2) Head of Product с CAC/LTV
$hop = [
    'title' => 'Head of Product',
    'description' => 'Владение CAC, LTV и монетизацией продукта, roadmap, backlog.',
];
$out = $guard->apply($fakeCommercialSkip, $hop);
$assert($out['verdict'] === 'maybe' && !in_array('FIT.commercial_primary', $out['decisive_factors'], true), 'HoP monetization: demote');

// 3) Product Owner e-commerce
$po = [
    'title' => 'Product Owner сайта / E-commerce',
    'description' => 'Интеграции платежей, каталог, сценарии покупателя, не продажи.',
];
$out = $guard->apply($fakeCommercialSkip, $po);
$assert($out['verdict'] === 'maybe', 'PO ecommerce: demote');

// 4) Head of Sales + РОПы — EXCLUDE-01 hard; commercial factor оставляем если sales title
$hos = [
    'title' => 'Head of Sales',
    'description' => 'План продаж, управление РОПами и отделом продаж, квота.',
];
$e01 = $exclusions->assessSalesManagersLeadership($hos);
$assert($e01->hardSkip, 'Head of Sales: EXCLUDE-01 hard_skip');
$out = $guard->apply($fakeCommercialSkip, $hos);
$assert($out['verdict'] === 'skip' && in_array('FIT.commercial_primary', $out['decisive_factors'], true), 'Head of Sales: keep commercial skip');

// 5) BDM лидоген — EXCLUDE-02 и/или sales title keep
$bdm = [
    'title' => 'Business Development Manager',
    'description' => 'Обязанности: самостоятельная лидогенерация и привлечение новых клиентов, развитие партнёрской сети.',
];
$e02 = $exclusions->assessLeadgenPartnershipsPrimary($bdm);
$assert($e02->hardSkip || !$guard->shouldDemoteCommercialSkip($bdm), 'BDM: hard commercial path');
$out = $guard->apply($fakeCommercialSkip, $bdm);
$assert($out['verdict'] === 'skip', 'BDM: keep skip');

// 6) CPO с основной обязанностью лично привлекать продавцов/клиентов — SKIP
$cpoSales = [
    'title' => 'CPO / Руководитель продукта',
    'description' => 'Ключевые задачи: активное привлечение новых клиентов и селлеров, личное привлечение продавцов, план продаж.',
];
$out = $guard->apply($fakeCommercialSkip, $cpoSales);
$assert($out['verdict'] === 'skip' && in_array('FIT.commercial_primary', $out['decisive_factors'], true), 'CPO sales-duty: keep skip');
$assert(!$guard->shouldDemoteCommercialSkip($cpoSales), 'CPO sales-duty: no demote');

// 7) Неоднозначная роль без sales title — maybe без commercial factor
$amb = [
    'title' => 'Руководитель направления',
    'description' => 'Развитие направления, коммерческий результат и координация команды.',
];
$out = $guard->apply($fakeCommercialSkip, $amb);
$assert($out['verdict'] === 'maybe' && !in_array('FIT.commercial_primary', $out['decisive_factors'], true), 'Ambiguous: maybe without commercial factor');

// apply без commercial factor не трогаем
$keep = $guard->apply([
    'verdict' => 'apply',
    'reason' => 'ok',
    'action' => 'Откликнуться',
    'decisive_factors' => [],
], $proteco);
$assert($keep['verdict'] === 'apply', 'apply without commercial factor untouched');

if ($failed > 0) {
    echo "\n$failed failed\n";
    exit(1);
}
echo "\nAll passed\n";
exit(0);
