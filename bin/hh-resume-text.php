#!/usr/bin/env php
<?php
declare(strict_types=1);

/** @deprecated php bin/hh.php resume-text [resumeId] */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$resumeId = $argv[1] ?? '';
$args = [$argv[0] ?? 'hh-resume-text.php', 'resume-text'];
if ($resumeId !== '') {
    $args[] = $resumeId;
}
exit((new JobSearch\Presentation\Cli\HhCli($root))->run($args));
