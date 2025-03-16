<?php

namespace Hasyirin\Actor\Concerns;

use Hasyirin\Actor\Facades\Actor;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @mixin Model
 */
trait InteractsWithActions
{
    public function act(string $action, ?Model $actor = null, ?Carbon $at = null): Action
    {
        /** @var Model $this */
        return Actor::act($this, $action, $actor, $at);
    }

    public function acted(string $action): bool
    {
        /** @var Model $this */
        return Actor::acted($this, $action);
    }

    public function actions(): MorphMany
    {
        return $this->morphMany(config('actor.models.action'), 'resource');
    }
}
