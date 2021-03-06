<?php

use Swh\FixFiles\Command\FixHashesCommand;
use Swh\FixFiles\Command\MergeDuplicatesCommand;

return [
    'file:fixhashes' => [
        'class' => FixHashesCommand::class,
        'schedulable' => false,
    ],
    'file:mergeduplicates' => [
        'class' => MergeDuplicatesCommand::class,
        'schedulable' => false,
    ],
];
