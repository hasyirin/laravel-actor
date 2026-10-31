<?php

namespace Hasyirin\Actor\Concerns;

use BackedEnum;
use DateTimeInterface;
use Hasyirin\Actor\Facades\Actor;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait InteractsWithActions
{
    public function act(BackedEnum|string $action, ?Model $actor = null, ?DateTimeInterface $at = null): Action
    {
        return Actor::act($this, $action, $actor, $at);
    }

    public function acted(BackedEnum|string $action, ?Model $actor = null): bool
    {
        if ($this->relationLoaded('actions')) {
            return $this->loadedAction($action, $actor) !== null;
        }

        return $this->actions()
            ->ofName($action)
            ->when($actor, fn ($query) => $query->ofActor($actor))
            ->exists();
    }

    public function action(BackedEnum|string $name, ?Model $actor = null): ?Action
    {
        if ($this->relationLoaded('actions')) {
            return $this->loadedAction($name, $actor);
        }

        return $this->actions()
            ->ofName($name)
            ->when($actor, fn ($query) => $query->ofActor($actor))
            ->first();
    }

    private function loadedAction(BackedEnum|string $name, ?Model $actor): ?Action
    {
        $value = $name instanceof BackedEnum ? (string) $name->value : $name;

        $collection = $this->getRelation('actions');

        if ($actor) {
            $collection = $collection
                ->where('actor_type', $actor->getMorphClass())
                ->where('actor_id', $actor->getKey());
        }

        return $collection->first(fn (Model $action) => (string) $action->getRawOriginal('name') === $value);
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
