<?php

namespace Hasyirin\Actor;

use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Actor
{
    public function act(Model $on, string $action, ?Model $actor = null, ?Carbon $at = null): Action
    {
        $actor ??= auth()->user();

        if (empty($actor)) {
            throw new \InvalidArgumentException('Actor is empty and no authenticated user to set as actor.');
        }

        /** @var Model $actor */

        return config('actor.models.action')::query()
            ->updateOrCreate([
                'resource_type' => $on->getMorphClass(),
                'resource_id' => $on->getKey(),
                'name' => $action,
            ], [
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'acted_at' => $at ?? now(),
            ]);
    }

    public function findAction(Model $on, string $action): ?Action
    {
        return config('actor.models.action')::query()
            ->latest('acted_at')
            ->ofResource($on)
            ->ofName($action)
            ->first();
    }

    public function acted(Model $on, string $action): bool
    {
        return config('actor.models.action')::query()
            ->latest('acted_at')
            ->ofResource($on)
            ->ofName($action)
            ->exists();
    }
}
