<?php

namespace Hasyirin\Actor;

use BackedEnum;
use DateTimeInterface;
use Hasyirin\Actor\Concerns\InteractsWithActions;
use Hasyirin\Actor\Events\ActionRecorded;
use Hasyirin\Actor\Exceptions\MissingActorException;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Model;

class Actor
{
    public function act(Model $on, BackedEnum|string $action, ?Model $actor = null, ?DateTimeInterface $at = null): Action
    {
        $actor ??= $this->authenticatedUser();

        if (! $actor) {
            throw MissingActorException::noAuthenticatedUser();
        }

        if (! $actor instanceof Model) {
            throw MissingActorException::authenticatedUserIsNotAModel($actor);
        }

        $class = config('actor.models.action', Action::class);

        $attributes = [
            'resource_type' => $on->getMorphClass(),
            'resource_id' => $on->getKey(),
            'name' => $action instanceof BackedEnum ? (string) $action->value : $action,
        ];

        $values = [
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'acted_at' => (new $class)->fromDateTime($at ?? now()),
        ];

        // Write first: a transaction that reads first fails at once on SQLite and on MariaDB under contention.
        // The read-back stays inside, so the upsert's row lock keeps a concurrent delete out until it is done.
        // The event stays inside, so it follows this transaction and not one open on another connection.
        // Laravel before 13.39 keeps the event of a failed COMMIT, so this needs Laravel 13.39 or later.
        $record = $class::query()->getConnection()->transaction(function () use ($class, $attributes, $values) {
            $class::query()->upsert([$attributes + $values], array_keys($attributes), array_keys($values));

            $record = $class::query()->where($attributes)->firstOrFail();

            event(new ActionRecorded($record));

            return $record;
        }, 3);

        if ($on->relationLoaded('actions') && in_array(InteractsWithActions::class, class_uses_recursive($on), true)) {
            $actions = $on->getRelation('actions');
            $index = $actions->search(fn (Model $loaded) => $loaded->getRawOriginal('name') === $record->getRawOriginal('name'));

            $on->setRelation('actions', $index === false ? $actions->concat([$record]) : $actions->replace([$index => $record]));
        }

        return $record;
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

    private function authenticatedUser(): mixed
    {
        return auth(config('actor.guard'))->user();
    }
}
