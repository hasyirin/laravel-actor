<?php

namespace Hasyirin\Actor;

use BackedEnum;
use Hasyirin\Actor\Exceptions\MissingActorException;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Actor
{
    public function act(Model $on, BackedEnum|string $action, ?Model $actor = null, ?Carbon $at = null): Action
    {
        $actor ??= auth(config('actor.guard'))->user();

        if (empty($actor)) {
            throw MissingActorException::noAuthenticatedUser();
        }

        /** @var Model $actor */

        return config('actor.models.action', Action::class)::query()
            ->updateOrCreate([
                'resource_type' => $on->getMorphClass(),
                'resource_id' => $on->getKey(),
                'name' => $action instanceof BackedEnum ? $action->value : $action,
            ], [
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'acted_at' => $at ?? now(),
            ]);
    }

    public function findAction(Model $on, BackedEnum|string $action, ?Model $actor = null): ?Action
    {
        return config('actor.models.action', Action::class)::query()
            ->ofResource($on)
            ->ofName($action)
            ->when($actor, fn ($query) => $query->ofActor($actor))
            ->first();
    }

    public function acted(Model $on, BackedEnum|string $action, ?Model $actor = null): bool
    {
        return config('actor.models.action', Action::class)::query()
            ->ofResource($on)
            ->ofName($action)
            ->when($actor, fn ($query) => $query->ofActor($actor))
            ->exists();
    }
}
