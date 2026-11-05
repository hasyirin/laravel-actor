<?php

namespace Hasyirin\Actor;

use BackedEnum;
use DateTimeInterface;
use Hasyirin\Actor\Concerns\InteractsWithActions;
use Hasyirin\Actor\Events\ActionRecorded;
use Hasyirin\Actor\Exceptions\MissingActorException;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Throwable;

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

        $class = $this->actionModel();

        $attributes = [
            'resource_type' => $on->getMorphClass(),
            'resource_id' => $on->getKey(),
            'name' => $action instanceof BackedEnum ? (string) $action->value : $action,
        ];

        $values = [
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'acted_at' => $at ?? now(),
        ];

        $connection = (new $class)->getConnection();
        $sqlite = $connection->getDriverName() === 'sqlite';

        $save = fn (bool $lock) => $connection->transaction(function () use ($class, $connection, $attributes, $values, $sqlite, $lock) {
            // SQLite has no row locks. A write as the first statement takes its write lock now; a read first fails at the upgrade.
            if ($sqlite) {
                $class::query()->toBase()->where($attributes)->update(['name' => $connection->raw('name')]);
            }

            // Dirty tracking needs the latest row, or two saves can mix their values.
            $record = $lock ? $class::query()->where($attributes)->lockForUpdate()->first() : null;

            // Hydration drops a ::write suffix, and a save on the plain connection would wait for this row's lock.
            $record?->setConnection((new $class)->getConnectionName());
            $record ??= (new $class)->setRawAttributes($attributes);

            // The event stays inside, so it follows this transaction and not one open on another connection.
            // Laravel before 13.39 keeps the event of a failed COMMIT, so this needs Laravel 13.39 or later.
            if ($record->forceFill($values)->save()) {
                event(new ActionRecorded($record));
            }

            return $record;
        }, 3);

        // This read stays outside the transaction, so a missing row takes no gap lock on InnoDB.
        $exists = $sqlite || $class::query()->where($attributes)->exists();

        // A first act that loses the race runs again with the lock. A delete can remove the winner's row, so it can lose again.
        $record = retry(
            3,
            fn (int $attempt) => $save($exists || $attempt > 1),
            when: fn (Throwable $e) => $e instanceof UniqueConstraintViolationException,
        );

        if (! $record->isDirty() && $on->relationLoaded('actions') && in_array(InteractsWithActions::class, class_uses_recursive($on), true)) {
            /** @var Collection<int, Model> $actions */
            $actions = $on->getRelation('actions');
            $index = $actions->search(fn (Model $loaded) => $loaded->getRawOriginal('name') === $record->getRawOriginal('name'));

            $on->setRelation('actions', $index === false ? $actions->concat([$record]) : $actions->replace([$index => $record]));
        }

        return $record;
    }

    public function findAction(Model $on, BackedEnum|string $action, ?Model $actor = null): ?Action
    {
        return $this->actionModel()::query()
            ->ofResource($on)
            ->ofName($action)
            ->when($actor, fn ($query, Model $actor) => $query->ofActor($actor))
            ->first();
    }

    public function acted(Model $on, BackedEnum|string $action, ?Model $actor = null): bool
    {
        return $this->actionModel()::query()
            ->ofResource($on)
            ->ofName($action)
            ->when($actor, fn ($query, Model $actor) => $query->ofActor($actor))
            ->exists();
    }

    /**
     * @return class-string<Action>
     */
    private function actionModel(): string
    {
        /** @var class-string<Action> $class */
        $class = config('actor.models.action', Action::class);

        return $class;
    }

    private function authenticatedUser(): mixed
    {
        /** @var string|null $guard */
        $guard = config('actor.guard');

        return auth($guard)->user();
    }
}
