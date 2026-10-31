<?php

use Hasyirin\Actor\Events\ActionRecorded;
use Hasyirin\Actor\Facades\Actor;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\ExampleActor;
use Workbench\App\Models\ExampleResource;

beforeEach(function () {
    $this->resource = ExampleResource::create(['name' => 'a post']);
    $this->actor = ExampleActor::create(['name' => 'alice']);
    $this->recorded = [];

    Event::listen(ActionRecorded::class, function (ActionRecorded $event) {
        $this->recorded[] = $event->action;
    });

    config()->set('database.connections.other', config('database.connections.'.DB::getDefaultConnection()));
});

function failCommitWhile(Closure $shouldFail): void
{
    Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use ($shouldFail) {
        if ($shouldFail()) {
            // A failed COMMIT ends the transaction on the database.
            $event->connection->getPdo()->rollBack();

            throw new RuntimeException('Deadlock found when trying to get lock');
        }
    });
}

it('dispatches ActionRecorded with the saved action', function () {
    $action = Actor::act($this->resource, 'approved', $this->actor);

    expect($this->recorded)->toBe([$action]);
});

it('dispatches ActionRecorded on a first act and on a repeat act through the trait', function () {
    $this->resource->act('approved', $this->actor);
    $this->resource->act('approved', $this->actor);

    expect($this->recorded)->toHaveCount(2);
});

it('dispatches ActionRecorded only after the caller’s transaction commits', function () {
    DB::transaction(function () {
        Actor::act($this->resource, 'approved', $this->actor);

        expect($this->recorded)->toBe([]);
    });

    expect($this->recorded)->toHaveCount(1);
});

it('does not dispatch ActionRecorded when the caller’s transaction rolls back', function () {
    expect(fn () => DB::transaction(function () {
        Actor::act($this->resource, 'approved', $this->actor);

        throw new RuntimeException('Roll back');
    }))->toThrow(RuntimeException::class, 'Roll back');

    expect($this->recorded)->toBe([]);
});

it('dispatches ActionRecorded once when act() retries after a deadlock', function () {
    $failed = false;
    DB::listen(function (QueryExecuted $query) use (&$failed) {
        if (! $failed && str_starts_with($query->sql, 'insert')) {
            $failed = true;

            throw new RuntimeException('Deadlock found when trying to get lock');
        }
    });

    Actor::act($this->resource, 'approved', $this->actor);

    expect($failed)->toBeTrue()
        ->and($this->recorded)->toHaveCount(1);
});

it('dispatches ActionRecorded once when the caller’s transaction runs again', function () {
    $attempts = 0;

    DB::transaction(function () use (&$attempts) {
        Actor::act($this->resource, 'approved', $this->actor);

        if (++$attempts === 1) {
            throw new RuntimeException('Deadlock found when trying to get lock');
        }
    }, 3);

    expect($attempts)->toBe(2)
        ->and($this->recorded)->toHaveCount(1);
});

it('dispatches ActionRecorded when a transaction on another connection rolls back around act()', function () {
    expect(fn () => DB::connection('other')->transaction(function () {
        Actor::act($this->resource, 'approved', $this->actor);

        throw new RuntimeException('Roll back');
    }))->toThrow(RuntimeException::class, 'Roll back');

    expect($this->recorded)->toHaveCount(1)
        ->and(Action::query()->count())->toBe(1);
});

it('does not dispatch ActionRecorded when the action’s connection rolls back after a transaction on another connection commits', function () {
    expect(fn () => DB::transaction(function () {
        DB::connection('other')->transaction(fn () => Actor::act($this->resource, 'approved', $this->actor));

        throw new RuntimeException('Roll back');
    }))->toThrow(RuntimeException::class, 'Roll back');

    expect($this->recorded)->toBe([])
        ->and(Action::query()->count())->toBe(0);
});

it('dispatches ActionRecorded once when COMMIT fails and act() runs again', function () {
    $commits = 0;
    failCommitWhile(function () use (&$commits) {
        return ++$commits === 1;
    });

    Actor::act($this->resource, 'approved', $this->actor);

    expect($commits)->toBe(2)
        ->and($this->recorded)->toHaveCount(1);
});

it('does not dispatch ActionRecorded when COMMIT fails on every attempt', function () {
    $fail = true;
    failCommitWhile(function () use (&$fail) {
        return $fail;
    });

    expect(fn () => Actor::act($this->resource, 'approved', $this->actor))
        ->toThrow(RuntimeException::class, 'Deadlock');

    $fail = false;
    DB::transaction(fn () => null);

    expect($this->recorded)->toBe([])
        ->and(Action::query()->count())->toBe(0);
});

it('keeps the saved row when a listener of ActionRecorded throws', function () {
    $this->resource->load('actions');

    Event::listen(ActionRecorded::class, function () {
        throw new RuntimeException('Listener failed');
    });

    expect(fn () => Actor::act($this->resource, 'approved', $this->actor))
        ->toThrow(RuntimeException::class, 'Listener failed');

    expect(Action::query()->count())->toBe(1)
        ->and(DB::transactionLevel())->toBe(0)
        ->and($this->resource->getRelation('actions'))->toBeEmpty();
});

it('reloads the action of ActionRecorded for a queued listener', function () {
    $event = new ActionRecorded(Actor::act($this->resource, 'approved', $this->actor));
    $bob = ExampleActor::create(['name' => 'bob']);
    Actor::act($this->resource, 'approved', $bob);

    expect(unserialize(serialize($event))->action->actor_id)->toBe($bob->getKey());
});
