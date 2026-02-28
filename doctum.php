<?php

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;
use Doctum\Version\GitVersionCollection;
use Symfony\Component\Finder\Finder;

$dir = __DIR__ . '/app';

$iterator = Finder::create()
    ->files()
    ->name('*.php')
    ->in($dir);

return new Doctum($iterator, [
    'title'                => 'Scouter - Documentation PHP',
    'build_dir'            => __DIR__ . '/docs/phpdoc',
    'cache_dir'            => __DIR__ . '/.doctum/cache',
    'default_opened_level' => 2,
    'include_parent_data'  => true,
]);
