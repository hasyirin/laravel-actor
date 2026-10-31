<?php

use Carbon\CarbonImmutable;
use Hasyirin\Actor\Exceptions\MissingActorException;
use Hasyirin\Actor\Facades\Actor;
use Hasyirin\Actor\Models\Action;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Enums\ExampleAction;
use Workbench\App\Enums\ExampleIntAction;
use Workbench\App\Models\ExampleActor;
use Workbench\App\Models\ExampleResource;

beforeEach(function () {
    $this->resource = ExampleResource::create(['name' => 'a post']);
    $this->actor = ExampleActor::create(['name' => 'alice']);
});

it('records an action', function () {
    $action = Actor::act($this->resource, 'approved', $this->actor);

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->resource_id)->toBe($this->resource->getKey())
        ->and($action->resource_type)->toBe($this->resource->getMorphClass())
        ->and($action->actor_id)->toBe($this->actor->getKey())
        ->and($action->actor_type)->toBe($this->actor->getMorphClass())
        ->and($action->name)->toBe('approved');
});

it('upserts on repeated act calls for the same (resource, name)', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    Actor::act($this->resource, 'approved', $this->actor);

    expect(Action::count())->toBe(1);
});

it('overwrites the actor on re-act by a different actor', function () {
    Actor::act($this->resource, 'approved', $this->actor);

    $bob = ExampleActor::create(['name' => 'bob']);
    Actor::act($this->resource, 'approved', $bob);

    expect(Action::count())->toBe(1)
        ->and(Action::first()->actor_id)->toBe($bob->getKey());
});

it('overwrites the time on re-act', function () {
    Actor::act($this->resource, 'approved', $this->actor, CarbonImmutable::parse('2026-01-02 03:04:05'));

    $action = Actor::act($this->resource, 'approved', $this->actor, CarbonImmutable::parse('2026-01-03 04:05:06'));

    expect($action->acted_at->format('Y-m-d H:i:s'))->toBe('2026-01-03 04:05:06');
});

it('keeps separate rows per (resource, name)', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    Actor::act($this->resource, 'reviewed', $this->actor);

    $other = ExampleResource::create(['name' => 'another']);
    Actor::act($other, 'approved', $this->actor);

    expect(Action::count())->toBe(3);
});

it('throws MissingActorException when actor is null and no auth user', function () {
    expect(fn () => Actor::act($this->resource, 'approved'))
        ->toThrow(MissingActorException::class);
});

it('falls back to the authenticated user when actor is omitted', function () {
    $this->actingAs($this->actor);

    $action = Actor::act($this->resource, 'approved');

    expect($action->actor_id)->toBe($this->actor->getKey());
});

it('respects a configured guard for the auth fallback', function () {
    config()->set('auth.guards.custom', [
        'driver' => 'session',
        'provider' => 'users',
    ]);

    auth()->guard('custom')->setUser($this->actor);

    expect(fn () => Actor::act($this->resource, 'approved'))
        ->toThrow(MissingActorException::class);

    config()->set('actor.guard', 'custom');

    expect(Actor::act($this->resource, 'approved')->actor_id)->toBe($this->actor->getKey());
});

it('throws MissingActorException when the authenticated user is not an Eloquent model', function () {
    $this->actingAs(new GenericUser(['id' => 1]));

    expect(fn () => Actor::act($this->resource, 'approved'))
        ->toThrow(MissingActorException::class, GenericUser::class);
});

it('throws MissingActorException when the guard returns false', function () {
    Auth::viaRequest('falsy', fn () => false);
    config()->set('auth.guards.falsy', ['driver' => 'falsy']);
    config()->set('actor.guard', 'falsy');

    expect(fn () => Actor::act($this->resource, 'approved'))
        ->toThrow(MissingActorException::class, 'No actor was provided');
});

it('throws MissingActorException when the guard returns a value that is not an object', function () {
    Auth::viaRequest('scalar', fn () => 'alice');
    config()->set('auth.guards.scalar', ['driver' => 'scalar']);
    config()->set('actor.guard', 'scalar');

    expect(fn () => Actor::act($this->resource, 'approved'))
        ->toThrow(MissingActorException::class, '[string]');
});

it('records an authenticated Eloquent model that does not implement Authenticatable', function () {
    $client = ExampleResource::create(['name' => 'api client']);
    Auth::viaRequest('client', fn () => $client);
    config()->set('auth.guards.client', ['driver' => 'client']);
    config()->set('actor.guard', 'client');

    $action = Actor::act($this->resource, 'approved');

    expect($action->actor_type)->toBe($client->getMorphClass())
        ->and($action->actor_id)->toBe($client->getKey());
});

it('uses the supplied $at timestamp', function () {
    $at = now()->subDay();

    $action = Actor::act($this->resource, 'approved', $this->actor, $at);

    expect($action->acted_at->format('Y-m-d H:i:s'))->toBe($at->format('Y-m-d H:i:s'));
});

it('accepts any DateTimeInterface as the $at timestamp', function () {
    $immutable = Actor::act($this->resource, 'approved', $this->actor, CarbonImmutable::parse('2026-01-02 03:04:05'));
    $native = Actor::act($this->resource, 'reviewed', $this->actor, new DateTimeImmutable('2026-01-03 04:05:06'));

    expect($immutable->acted_at->format('Y-m-d H:i:s'))->toBe('2026-01-02 03:04:05')
        ->and($native->acted_at->format('Y-m-d H:i:s'))->toBe('2026-01-03 04:05:06');
});

it('act() writes acted_at in the date format of the action model', function () {
    $model = new class extends Action
    {
        protected $dateFormat = 'Y-m-d\TH:i:s';
    };
    config()->set('actor.models.action', $model::class);
    DB::enableQueryLog();

    Actor::act($this->resource, 'approved', $this->actor, CarbonImmutable::parse('2026-01-02 03:04:05'));

    expect(DB::getQueryLog()[0]['bindings'])->toContain('2026-01-02T03:04:05');
});

it('act() writes one upsert and reads the row back, without Eloquent write events', function () {
    $bob = ExampleActor::create(['name' => 'bob']);
    $events = [];
    Action::creating(function () use (&$events) {
        $events[] = 'creating';
    });
    Action::updating(function () use (&$events) {
        $events[] = 'updating';
    });
    DB::enableQueryLog();

    Actor::act($this->resource, 'approved', $this->actor);
    Actor::act($this->resource, 'approved', $bob);

    $statements = collect(DB::getQueryLog())->map(fn (array $query) => strtok($query['query'], ' '))->all();

    expect($events)->toBe([])
        ->and($statements)->toBe(['insert', 'select', 'insert', 'select']);
});

it('act() updates a loaded actions relation without querying it again', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    $this->resource->load('actions');
    $bob = ExampleActor::create(['name' => 'bob']);

    Actor::act($this->resource, 'approved', $bob);
    Actor::act($this->resource, 'reviewed', $this->actor);

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($this->resource->actions->map(fn (Action $action) => [$action->name, $action->actor_id])->all())->toBe([
        ['approved', $bob->getKey()],
        ['reviewed', $this->actor->getKey()],
    ])->and(DB::getQueryLog())->toBeEmpty();
});

it('act() keeps an updated row at its position in a loaded relation', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    Actor::act($this->resource, 'reviewed', $this->actor);
    $this->resource->load('actions');
    $names = $this->resource->actions->pluck('name')->all();

    Actor::act($this->resource, $names[0], ExampleActor::create(['name' => 'bob']));

    expect($this->resource->actions->pluck('name')->all())->toBe($names);
});

it('act() replaces a loaded row of the same name when the database row was replaced', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    $this->resource->load('actions');
    Action::query()->delete();

    $action = Actor::act($this->resource, 'approved', $this->actor);

    expect($this->resource->getRelation('actions'))->toHaveCount(1)
        ->and($this->resource->getRelation('actions')->first())->toBe($action);
});

it('act() replaces a loaded row that was selected without its key', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    $this->resource->load('actions:resource_type,resource_id,name');

    $action = Actor::act($this->resource, 'approved', ExampleActor::create(['name' => 'bob']));

    expect($this->resource->getRelation('actions')->all())->toBe([$action]);
});

it('act() replaces a loaded row when the database treats the new name as the same name', function () {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Only utf8mb4_bin ignores trailing spaces.');
    }

    Actor::act($this->resource, 'approved', $this->actor);
    $this->resource->load('actions');

    $action = Actor::act($this->resource, 'approved ', ExampleActor::create(['name' => 'bob']));

    expect(Action::query()->count())->toBe(1)
        ->and($this->resource->getRelation('actions')->all())->toBe([$action]);
});

it('act() leaves the loaded relation of a copy of the model alone', function (string $name) {
    Actor::act($this->resource, 'approved', $this->actor);
    $this->resource->load('actions');
    $loaded = $this->resource->getRelation('actions')->all();
    $copy = clone $this->resource;
    $bob = ExampleActor::create(['name' => 'bob']);

    $copy->act($name, $bob);

    expect($this->resource->getRelation('actions')->all())->toBe($loaded)
        ->and($copy->acted($name, $bob))->toBeTrue();
})->with([
    'when it replaces a row' => 'approved',
    'when it adds a row' => 'reviewed',
]);

it('act() accepts a loaded actions relation that holds another model class', function () {
    $this->resource->setRelation('actions', new Collection([$this->actor]));

    Actor::act($this->resource, 'approved', $this->actor);

    expect($this->resource->getRelation('actions'))->toHaveCount(2);
});

it('act() throws and leaves the row alone when the row is soft-deleted', function () {
    $model = new class extends Action
    {
        use SoftDeletes;
    };
    Schema::table('actions', fn (Blueprint $table) => $table->softDeletes());
    config()->set('actor.models.action', $model::class);
    Actor::act($this->resource, 'approved', $this->actor)->delete();
    $bob = ExampleActor::create(['name' => 'bob']);

    expect(fn () => Actor::act($this->resource, 'approved', $bob))->toThrow(ModelNotFoundException::class)
        ->and($model::withTrashed()->sole()->actor_id)->toBe($this->actor->getKey());
});

it('act() retries when the database reports a deadlock', function () {
    $failed = false;
    DB::listen(function (QueryExecuted $query) use (&$failed) {
        if (! $failed && str_starts_with($query->sql, 'insert')) {
            $failed = true;

            throw new RuntimeException('Deadlock found when trying to get lock');
        }
    });

    $action = Actor::act($this->resource, 'approved', $this->actor);

    expect($action->exists)->toBeTrue()
        ->and(Action::query()->sole()->actor_id)->toBe($this->actor->getKey());
});

it('act() keeps names that differ only in case or accents apart', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    Actor::act($this->resource, 'Approved', ExampleActor::create(['name' => 'bob']));
    Actor::act($this->resource, 'approvéd', ExampleActor::create(['name' => 'carol']));

    expect(Action::query()->count())->toBe(3)
        ->and(Actor::findAction($this->resource, 'approved')->actor_id)->toBe($this->actor->getKey());
});

it('act() leaves an actions relation alone on a model without the trait', function () {
    $ownActions = new Collection;
    $this->actor->setRelation('actions', $ownActions);

    Actor::act($this->actor, 'approved', $this->actor);

    expect($this->actor->getRelations())->toBe(['actions' => $ownActions])
        ->and($ownActions)->toBeEmpty();
});

it('acted() is false before any action', function () {
    expect(Actor::acted($this->resource, 'approved'))->toBeFalse();
});

it('acted() is true after an action', function () {
    Actor::act($this->resource, 'approved', $this->actor);

    expect(Actor::acted($this->resource, 'approved'))->toBeTrue();
});

it('acted() filters by actor when supplied', function () {
    Actor::act($this->resource, 'approved', $this->actor);

    $bob = ExampleActor::create(['name' => 'bob']);

    expect(Actor::acted($this->resource, 'approved', $this->actor))->toBeTrue()
        ->and(Actor::acted($this->resource, 'approved', $bob))->toBeFalse();
});

it('findAction() returns the matching row', function () {
    Actor::act($this->resource, 'approved', $this->actor);

    $action = Actor::findAction($this->resource, 'approved');

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->actor_id)->toBe($this->actor->getKey());
});

it('findAction() returns null when nothing matches', function () {
    expect(Actor::findAction($this->resource, 'approved'))->toBeNull();
});

it('findAction() filters by actor when supplied', function () {
    Actor::act($this->resource, 'approved', $this->actor);

    $bob = ExampleActor::create(['name' => 'bob']);

    expect(Actor::findAction($this->resource, 'approved', $this->actor))->not->toBeNull()
        ->and(Actor::findAction($this->resource, 'approved', $bob))->toBeNull();
});

it('act() accepts a BackedEnum and stores its value', function () {
    $action = Actor::act($this->resource, ExampleAction::Approved, $this->actor);

    expect($action->name)->toBe('approved');
});

it('act() with BackedEnum upserts against a string with the same value', function () {
    Actor::act($this->resource, 'approved', $this->actor);
    Actor::act($this->resource, ExampleAction::Approved, $this->actor);

    expect(Action::count())->toBe(1);
});

it('acted() accepts a BackedEnum', function () {
    Actor::act($this->resource, 'approved', $this->actor);

    expect(Actor::acted($this->resource, ExampleAction::Approved))->toBeTrue()
        ->and(Actor::acted($this->resource, ExampleAction::Reviewed))->toBeFalse();
});

it('findAction() accepts a BackedEnum', function () {
    Actor::act($this->resource, 'approved', $this->actor);

    $action = Actor::findAction($this->resource, ExampleAction::Approved);

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->name)->toBe('approved');
});

it('act() stores an int-backed enum value as a string', function () {
    $action = Actor::act($this->resource, ExampleIntAction::Published, $this->actor);

    expect($action->name)->toBe('1');
});

it('acted() and findAction() bind an int-backed enum name as a string', function () {
    DB::enableQueryLog();

    Actor::acted($this->resource, ExampleIntAction::Draft);
    Actor::findAction($this->resource, ExampleIntAction::Draft);

    $nameBindings = collect(DB::getQueryLog())->map(fn (array $query) => last($query['bindings']));

    expect($nameBindings->all())->toBe(['0', '0']);
});
