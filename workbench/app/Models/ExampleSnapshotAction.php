<?php

namespace Workbench\App\Models;

use Hasyirin\Actor\Models\Action;

/**
 * @property string|null $actor_name
 */
class ExampleSnapshotAction extends Action
{
    protected static function booted(): void
    {
        static::saving(function (ExampleSnapshotAction $action) {
            $action->actor_name = $action->actor->getAttribute('name');
        });
    }
}
