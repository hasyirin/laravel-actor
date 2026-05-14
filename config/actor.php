<?php

use Hasyirin\Actor\Models\Action;

return [
    'tables' => [
        'actions' => 'actions',
    ],

    'models' => [
        'action' => Action::class,
    ],

    'guard' => null,
];
