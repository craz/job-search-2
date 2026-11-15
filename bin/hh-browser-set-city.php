#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Совместимость: старый entrypoint → единый CLI.
 * Usage: php bin/hh-browser-set-city.php <Город>
 */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$city = $argv[1] ?? '';

exit((new JobSearch\Presentation\Cli\HhCli($root))->run([
    $argv[0] ?? 'hh-browser-set-city.php',
    'set-city',
    $city,
]));
