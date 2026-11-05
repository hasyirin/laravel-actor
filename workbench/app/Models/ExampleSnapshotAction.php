<?php

namespace Workbench\App\Models;

use Hasyirin\Actor\Models\Action;
use LogicException;

/**
 * @property string|null $actor_name
 */
class ExampleSnapshotAction extends Action
{
    protected static function booted(): void
    {
        static::saving(function (ExampleSnapshotAction $action) {
            $name = $action->actor?->getAttribute('name');
            $action->actor_name = is_string($name) ? $name : throw new LogicException('The actor has no name.');
        });
    }
}
