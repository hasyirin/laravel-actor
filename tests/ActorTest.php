<?php

use Hasyirin\Actor\Exceptions\MissingActorException;
use Hasyirin\Actor\Facades\Actor;
use Hasyirin\Actor\Models\Action;
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
    config()->set('actor.guard', 'custom');

    $this->actingAs($this->actor, 'custom');

    $action = Actor::act($this->resource, 'approved');

    expect($action->actor_id)->toBe($this->actor->getKey());
});

it('uses the supplied $at timestamp', function () {
    $at = now()->subDay();

    $action = Actor::act($this->resource, 'approved', $this->actor, $at);

    expect($action->acted_at->format('Y-m-d H:i:s'))->toBe($at->format('Y-m-d H:i:s'));
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
