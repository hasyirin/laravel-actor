<?php

namespace Hasyirin\Actor\Concerns;

use BackedEnum;
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
    public function act(BackedEnum|string $action, ?Model $actor = null, ?Carbon $at = null): Action
    {
        return Actor::act($this, $action, $actor, $at);
    }

    public function acted(BackedEnum|string $action, ?Model $actor = null): bool
    {
        return Actor::acted($this, $action, $actor);
    }

    public function action(BackedEnum|string $name, ?Model $actor = null): ?Action
    {
        $name = $name instanceof BackedEnum ? $name->value : $name;

        if ($this->relationLoaded('actions')) {
            $collection = $this->actions;

            if ($actor) {
                $collection = $collection
                    ->where('actor_type', $actor->getMorphClass())
                    ->where('actor_id', $actor->getKey());
            }

            return $collection->firstWhere('name', $name);
        }

        return $this->actions()
            ->where('name', $name)
            ->when($actor, fn ($query) => $query->ofActor($actor))
            ->first();
    }

    /**
     * @return MorphMany<Action, $this>
     */
    public function actions(): MorphMany
    {
        /** @var class-string<Action> $class */
        $class = config('actor.models.action', Action::class);

        return $this->morphMany($class, 'resource');
    }
}
