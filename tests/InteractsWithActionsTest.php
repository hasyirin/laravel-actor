<?php

use Hasyirin\Actor\Concerns\InteractsWithActions;
use Hasyirin\Actor\Models\Action;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Workbench\App\Enums\ExampleAction;
use Workbench\App\Enums\ExampleIntAction;
use Workbench\App\Models\ExampleActor;
use Workbench\App\Models\ExampleCastAction;
use Workbench\App\Models\ExampleFilteredResource;
use Workbench\App\Models\ExampleResource;

beforeEach(function () {
    $this->resource = ExampleResource::create(['name' => 'a post']);
    $this->actor = ExampleActor::create(['name' => 'alice']);
});

it('records via trait act()', function () {
    $action = $this->resource->act('approved', $this->actor);

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->name)->toBe('approved')
        ->and($action->actor_id)->toBe($this->actor->getKey());
});

it('trait acted() is false then true', function () {
    expect($this->resource->acted('approved'))->toBeFalse();

    $this->resource->act('approved', $this->actor);

    expect($this->resource->acted('approved'))->toBeTrue();
});

it('trait acted() filters by actor', function () {
    $this->resource->act('approved', $this->actor);

    $bob = ExampleActor::create(['name' => 'bob']);

    expect($this->resource->acted('approved', $this->actor))->toBeTrue()
        ->and($this->resource->acted('approved', $bob))->toBeFalse();
});

it('trait acted() uses the loaded relation when present (no extra query)', function () {
    $this->resource->act('approved', $this->actor);
    $bob = ExampleActor::create(['name' => 'bob']);
    $this->resource->load('actions');

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($this->resource->acted('approved'))->toBeTrue()
        ->and($this->resource->acted('approved', $bob))->toBeFalse()
        ->and($this->resource->acted('reviewed'))->toBeFalse()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('trait action() returns the matching record', function () {
    $this->resource->act('approved', $this->actor);

    $action = $this->resource->action('approved');

    expect($action)->toBeInstanceOf(Action::class)
        ->and($action->actor_id)->toBe($this->actor->getKey());
});

it('trait action() returns null when nothing matches', function () {
    expect($this->resource->action('approved'))->toBeNull();
});

it('trait action() filters by actor', function () {
    $this->resource->act('approved', $this->actor);

    $bob = ExampleActor::create(['name' => 'bob']);

    expect($this->resource->action('approved', $this->actor))->not->toBeNull()
        ->and($this->resource->action('approved', $bob))->toBeNull();
});

it('trait action() uses the loaded relation when present (no extra query)', function () {
    $this->resource->act('approved', $this->actor);
    $this->resource->load('actions');

    DB::enableQueryLog();
    DB::flushQueryLog();

    $result = $this->resource->action('approved');

    expect($result)->not->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('trait action() filters loaded relation by actor (no extra query)', function () {
    $this->resource->act('approved', $this->actor);
    $bob = ExampleActor::create(['name' => 'bob']);
    $this->resource->load('actions');

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($this->resource->action('approved', $this->actor))->not->toBeNull()
        ->and($this->resource->action('approved', $bob))->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('actions() relation returns all actions on the resource', function () {
    $this->resource->act('approved', $this->actor);
    $this->resource->act('reviewed', $this->actor);

    expect($this->resource->actions)->toHaveCount(2);
});

it('trait act() honors the supplied $at timestamp', function () {
    $at = now()->subDay();

    $action = $this->resource->act('approved', $this->actor, $at);

    expect($action->acted_at->format('Y-m-d H:i:s'))->toBe($at->format('Y-m-d H:i:s'));
});

it('trait act() accepts a BackedEnum', function () {
    $action = $this->resource->act(ExampleAction::Approved, $this->actor);

    expect($action->name)->toBe('approved');
});

it('trait acted() accepts a BackedEnum', function () {
    $this->resource->act('approved', $this->actor);

    expect($this->resource->acted(ExampleAction::Approved))->toBeTrue()
        ->and($this->resource->acted(ExampleAction::Reviewed))->toBeFalse();
});

it('trait action() accepts a BackedEnum', function () {
    $this->resource->act('approved', $this->actor);

    expect($this->resource->action(ExampleAction::Approved))->not->toBeNull()
        ->and($this->resource->action(ExampleAction::Reviewed))->toBeNull();
});

it('trait action() accepts a BackedEnum against a loaded relation (no extra query)', function () {
    $this->resource->act('approved', $this->actor);
    $this->resource->load('actions');

    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($this->resource->action(ExampleAction::Approved))->not->toBeNull()
        ->and(DB::getQueryLog())->toBeEmpty();
});

it('trait action() finds an int-backed enum name in a loaded relation', function () {
    $this->resource->act(ExampleIntAction::Published, $this->actor);
    $this->resource->load('actions');

    expect($this->resource->action(ExampleIntAction::Published))->not->toBeNull();
});

it('trait action() finds a loaded action when the action model casts name', function () {
    config()->set('actor.models.action', ExampleCastAction::class);
    $this->resource->act(ExampleIntAction::Published, $this->actor);
    $this->resource->load('actions');

    expect($this->resource->action(ExampleIntAction::Published))->not->toBeNull();
});

it('trait action() finds an action recorded after load when the action model casts name', function () {
    config()->set('actor.models.action', ExampleCastAction::class);
    $this->resource->load('actions');

    $this->resource->act(ExampleIntAction::Published, $this->actor);

    expect($this->resource->action(ExampleIntAction::Published))->not->toBeNull();
});

it('trait action() sees an action recorded after actions were loaded', function () {
    $this->resource->load('actions');

    $this->resource->act('approved', $this->actor);

    expect($this->resource->action('approved'))->not->toBeNull();
});

it('trait action() sees the new actor after a re-act on a loaded relation', function () {
    $this->resource->act('approved', $this->actor);
    $bob = ExampleActor::create(['name' => 'bob']);
    $this->resource->load('actions');

    $this->resource->act('approved', $bob);

    expect($this->resource->action('approved')->actor_id)->toBe($bob->getKey())
        ->and($this->resource->action('approved', $this->actor))->toBeNull();
});

it('trait action() and actions read the connection that act() writes to', function () {
    config()->set('database.connections.secondary', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
    $this->resource->setConnection('secondary');

    $this->resource->act('approved', $this->actor);

    expect($this->resource->action('approved'))->not->toBeNull()
        ->and($this->resource->actions)->toHaveCount(1);
});

it('trait action() and acted() accept a loaded actions relation that holds another model class', function () {
    $this->resource->setRelation('actions', new Collection([$this->actor]));

    expect($this->resource->action('approved'))->toBeNull()
        ->and($this->resource->acted('approved'))->toBeFalse();
});

it('trait action() and acted() read through an overridden actions() relation', function () {
    $resource = ExampleFilteredResource::create(['name' => 'a post']);
    $resource->act('hidden', $this->actor);
    $resource->act('approved', $this->actor);

    expect($resource->action('hidden'))->toBeNull()
        ->and($resource->acted('hidden'))->toBeFalse()
        ->and($resource->action('approved'))->not->toBeNull()
        ->and($resource->acted('approved'))->toBeTrue();
});

it('trait acted() runs an exists query when actions are not loaded', function () {
    $this->resource->act('approved', $this->actor);
    DB::enableQueryLog();
    DB::flushQueryLog();

    expect($this->resource->acted('approved'))->toBeTrue()
        ->and(DB::getQueryLog())->toHaveCount(1)
        ->and(DB::getQueryLog()[0]['query'])->toStartWith('select exists');
});

it('trait acted() does not go through a model method named action()', function () {
    $model = new class extends Model
    {
        use InteractsWithActions;

        protected $table = 'example_resources';

        protected $guarded = [];

        public function action(): BelongsTo
        {
            return $this->belongsTo(ExampleActor::class, 'name');
        }
    };
    $resource = $model->newQuery()->create(['name' => 'a post']);

    expect($resource->acted('approved'))->toBeFalse();

    $resource->load('actions');

    expect($resource->acted('approved'))->toBeFalse();
});

it('trait action() reads the loaded relation even when the model has an actions attribute', function () {
    $this->resource->act('approved', $this->actor);
    $this->resource->load('actions');
    $this->resource->setRawAttributes([...$this->resource->getAttributes(), 'actions' => '[]']);

    expect($this->resource->action('approved'))->not->toBeNull();
});

it('trait action() matches names exactly against a loaded relation', function () {
    $this->resource->act('01', $this->actor);
    $this->resource->load('actions');

    expect($this->resource->action('1'))->toBeNull()
        ->and($this->resource->action('1e0'))->toBeNull()
        ->and($this->resource->action('01'))->not->toBeNull();
});

it('fills acted_at when an action is created without it', function () {
    $this->freezeTime();

    $action = $this->resource->actions()->create([
        'actor_type' => $this->actor->getMorphClass(),
        'actor_id' => $this->actor->getKey(),
        'name' => 'reviewed',
    ]);

    expect($action->acted_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('fills acted_at on an action model that overrides booted()', function () {
    $this->freezeTime();

    $action = new class extends Action
    {
        protected static function booted() {}
    };

    $action->fill([
        'resource_type' => $this->resource->getMorphClass(),
        'resource_id' => $this->resource->getKey(),
        'actor_type' => $this->actor->getMorphClass(),
        'actor_id' => $this->actor->getKey(),
        'name' => 'reviewed',
    ])->save();

    expect($action->acted_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});
