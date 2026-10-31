<?php

namespace Workbench\App\Models;

use Hasyirin\Actor\Models\Action;
use Workbench\App\Enums\ExampleIntAction;

class ExampleCastAction extends Action
{
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'name' => ExampleIntAction::class,
        ];
    }
}
