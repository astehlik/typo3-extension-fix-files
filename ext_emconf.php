<?php
/** @var string $_EXTKEY */
$EM_CONF[$_EXTKEY] = [
    'title' => 'Commands for fixing files',
    'description' => 'Fix file hashes, merge duplicates',
    'category' => 'misc',
    'version' => '1.0.0',
    'state' => 'stable',
    'uploadfolder' => 0,
    'createDirs' => '',
    'clearcacheonload' => 0,
    'author' => 'Alexander Stehlik',
    'author_email' => 'alexander.stehlik.deleteme@gmail.com',
    'author_company' => '',
    'constraints' => [
        'depends' => ['typo3' => '10.4.0-10.4.99'],
        'conflicts' => [],
        'suggests' => [],
    ],
];
