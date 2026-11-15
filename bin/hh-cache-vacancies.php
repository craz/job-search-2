#!/usr/bin/env php
<?php
declare(strict_types=1);

/** @deprecated php bin/hh.php vacancies [resumeId] */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$resumeId = $argv[1] ?? '';
$args = [$argv[0] ?? 'hh-cache-vacancies.php', 'vacancies'];
if ($resumeId !== '') {
    $args[] = $resumeId;
}
exit((new JobSearch\Presentation\Cli\HhCli($root))->run($args));
