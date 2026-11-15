#!/usr/bin/env php
<?php
declare(strict_types=1);

/** @deprecated php bin/hh.php refresh */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
exit((new JobSearch\Presentation\Cli\HhCli($root))->run([$argv[0] ?? 'hh-cache-refresh.php', 'refresh']));
