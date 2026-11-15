#!/usr/bin/env php
<?php
declare(strict_types=1);

/** @deprecated php bin/hh.php job-set-city <Город> */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$city = $argv[1] ?? '';
exit((new JobSearch\Presentation\Cli\HhCli($root))->run([
    $argv[0] ?? 'hh-cache-set-city.php',
    'job-set-city',
    $city,
]));
