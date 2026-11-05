<?php

use Hasyirin\Actor\Events\ActionRecorded;
use Hasyirin\Actor\Facades\Actor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\ExampleActor;
use Workbench\App\Models\ExampleResource;
use Workbench\App\Models\ExampleSnapshotAction;

beforeEach(function () {
    Schema::table('actions', fn (Blueprint $table) => $table->string('actor_name')->nullable());
    config()->set('actor.models.action', ExampleSnapshotAction::class);

    $this->resource = ExampleResource::create(['name' => 'a post']);
    $this->alice = ExampleActor::create(['name' => 'alice']);
    $this->bob = ExampleActor::create(['name' => 'bob']);
});

it('runs a saving observer on a first act', function () {
    $action = Actor::act($this->resource, 'approved', $this->alice);

    expect($action->actor_name)->toBe('alice')
        ->and(ExampleSnapshotAction::query()->sole()->actor_name)->toBe('alice');
});

it('runs a saving observer on a repeat act by another actor', function () {
    Actor::act($this->resource, 'approved', $this->alice);

    $action = Actor::act($this->resource, 'approved', $this->bob);

    $row = ExampleSnapshotAction::query()->sole();

    expect($action->actor_name)->toBe('bob')
        ->and($row->actor_id)->toBe($this->bob->getKey())
        ->and($row->actor_name)->toBe('bob');
});

it('runs a saving observer with the new actor when the action model eager-loads its actor', function () {
    $model = new class extends ExampleSnapshotAction
    {
        protected $with = ['actor'];
    };
    config()->set('actor.models.action', $model::class);

    Actor::act($this->resource, 'approved', $this->alice);
    $action = Actor::act($this->resource, 'approved', $this->bob);

    expect($action->actor_name)->toBe('bob')
        ->and($action->actor->is($this->bob))->toBeTrue()
        ->and($model::query()->sole()->actor_name)->toBe('bob');
});

it('works with a required column that a saving observer fills', function () {
    Schema::table('actions', fn (Blueprint $table) => $table->string('actor_name')->nullable(false)->change());

    Actor::act($this->resource, 'approved', $this->alice);
    Actor::act($this->resource, 'approved', $this->bob);

    expect(ExampleSnapshotAction::query()->sole()->actor_name)->toBe('bob');
});

it('fires the model events of a first act and of a repeat act', function () {
    $events = [];

    foreach (['saving', 'creating', 'created', 'updating', 'updated', 'saved'] as $event) {
        ExampleSnapshotAction::$event(function () use (&$events, $event) {
            $events[] = $event;
        });
    }

    Actor::act($this->resource, 'approved', $this->alice);
    $first = $events;
    $events = [];

    Actor::act($this->resource, 'approved', $this->bob);

    expect($first)->toBe(['saving', 'creating', 'created', 'saved'])
        ->and($events)->toBe(['saving', 'updating', 'updated', 'saved']);
});

it('shows an observer the old row and the changed columns on a repeat act', function () {
    Actor::act($this->resource, 'approved', $this->alice);

    $old = null;
    $changed = null;
    ExampleSnapshotAction::updated(function (ExampleSnapshotAction $action) use (&$old, &$changed) {
        $old = $action->getOriginal('actor_id');
        $changed = array_keys($action->getChanges());
    });

    Actor::act($this->resource, 'approved', $this->bob);

    expect($old)->toBe($this->alice->getKey())
        ->and($changed)->toContain('actor_id', 'actor_name');
});

it('applies mutators to the values that act() writes', function () {
    $model = new class extends ExampleSnapshotAction
    {
        public function setActorNameAttribute(?string $value): void
        {
            $this->attributes['actor_name'] = strtoupper((string) $value);
        }
    };
    config()->set('actor.models.action', $model::class);

    Actor::act($this->resource, 'approved', $this->alice);

    expect($model::query()->sole()->actor_name)->toBe('ALICE');
});

it('writes the key columns as given, so a mutator cannot break the lookup of the row', function () {
    $model = new class extends ExampleSnapshotAction
    {
        public function setNameAttribute(string $value): void
        {
            $this->attributes['name'] = strtoupper($value);
        }
    };
    config()->set('actor.models.action', $model::class);

    Actor::act($this->resource, 'approved', $this->alice);
    Actor::act($this->resource, 'approved', $this->bob);

    $row = $model::query()->sole();

    expect($row->getRawOriginal('name'))->toBe('approved')
        ->and($row->actor_id)->toBe($this->bob->getKey());
});

it('rolls the act back when an observer throws', function () {
    Actor::act($this->resource, 'approved', $this->alice);

    ExampleSnapshotAction::updated(function () {
        throw new RuntimeException('Observer failed');
    });

    expect(fn () => Actor::act($this->resource, 'approved', $this->bob))
        ->toThrow(RuntimeException::class, 'Observer failed');

    $row = ExampleSnapshotAction::query()->sole();

    expect($row->actor_id)->toBe($this->alice->getKey())
        ->and($row->actor_name)->toBe('alice');
});

it('rolls back what an observer wrote and runs it again when act() retries', function () {
    $runs = 0;

    ExampleSnapshotAction::created(function () use (&$runs) {
        ExampleResource::create(['name' => 'audit']);

        if (++$runs === 1) {
            throw new RuntimeException('Deadlock found when trying to get lock');
        }
    });

    Actor::act($this->resource, 'approved', $this->alice);

    expect($runs)->toBe(2)
        ->and(ExampleResource::query()->where('name', 'audit')->count())->toBe(1);
});

it('writes nothing and fires no ActionRecorded when a listener cancels a first act', function () {
    $recorded = 0;
    Event::listen(ActionRecorded::class, function () use (&$recorded) {
        $recorded++;
    });
    ExampleSnapshotAction::creating(fn () => false);
    $this->resource->load('actions');

    $action = Actor::act($this->resource, 'approved', $this->alice);

    expect($action->exists)->toBeFalse()
        ->and(ExampleSnapshotAction::query()->count())->toBe(0)
        ->and($recorded)->toBe(0)
        ->and($this->resource->getRelation('actions'))->toBeEmpty();
});

it('writes nothing and fires no ActionRecorded when a listener cancels a repeat act', function () {
    Actor::act($this->resource, 'approved', $this->alice);

    $recorded = 0;
    Event::listen(ActionRecorded::class, function () use (&$recorded) {
        $recorded++;
    });
    ExampleSnapshotAction::updating(fn () => false);
    $this->resource->load('actions');
    $loaded = $this->resource->getRelation('actions')->all();

    $action = Actor::act($this->resource, 'approved', $this->bob);

    expect($action->isDirty('actor_id'))->toBeTrue()
        ->and(ExampleSnapshotAction::query()->sole()->actor_id)->toBe($this->alice->getKey())
        ->and($recorded)->toBe(0)
        ->and($this->resource->getRelation('actions')->all())->toBe($loaded);
});
