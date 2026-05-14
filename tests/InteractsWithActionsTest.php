<?php

use Hasyirin\Actor\Models\Action;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\ExampleActor;
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
