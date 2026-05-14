# Laravel Actor

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hasyirin/laravel-actor.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-actor)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-actor/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/hasyirin/laravel-actor/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-actor/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/hasyirin/laravel-actor/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hasyirin/laravel-actor.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-actor)

A small polymorphic action log for Eloquent models. Record that an actor (any model — typically a `User`) performed a named action on a resource (any model), with the time it happened. Each `(resource, name)` pair holds a single latest-state row.

## Installation

```bash
composer require hasyirin/laravel-actor
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag="laravel-actor-migrations"
php artisan migrate
```

Optionally publish the config:

```bash
php artisan vendor:publish --tag="laravel-actor-config"
```

Published config:

```php
return [
    'tables' => [
        'actions' => 'actions',
    ],

    'models' => [
        'action' => \Hasyirin\Actor\Models\Action::class,
    ],

    'guard' => null,
];
```

## Usage

Add the trait to any model that should be a resource (the thing being acted on):

```php
use Hasyirin\Actor\Concerns\InteractsWithActions;

class Post extends Model
{
    use InteractsWithActions;
}
```

Record an action. The current authenticated user is used as the actor unless you pass one explicitly:

```php
$post->act('approved');                          // actor = auth()->user()
$post->act('approved', $editor);                 // explicit actor
$post->act('approved', $editor, now()->subDay()); // explicit time
```

Check whether an action has been recorded:

```php
$post->acted('approved');           // bool — anyone approved?
$post->acted('approved', $user);    // bool — is $user the current approver?
```

Fetch the action record (or null):

```php
$action = $post->action('approved');
$action?->actor;                    // the user who acted
$action?->acted_at;

$mine = $post->action('approved', $user);  // only if $user is the current approver
```

Get all actions on the resource (eager-loadable):

```php
$post->load('actions');
$post->actions; // Collection<Action>
```

You can also use the facade directly when you don't have a trait-equipped model:

```php
use Hasyirin\Actor\Facades\Actor;

Actor::act($post, 'approved', $editor);
Actor::acted($post, 'approved');                // anyone?
Actor::acted($post, 'approved', $editor);       // is $editor the current actor?
Actor::findAction($post, 'approved');
Actor::findAction($post, 'approved', $editor);  // only if $editor is the current actor
```

### Latest-state semantics

`act()` is an upsert keyed on `(resource_type, resource_id, name)`. Re-acting the same action on the same resource overwrites the actor and timestamp — there is only ever one row per `(resource, name)`. If you need a full event history, this package is not the right choice.

### Authentication guard

If the actor is resolved from `auth()`, the package uses Laravel's default guard. Override via config:

```php
// config/actor.php
'guard' => 'sanctum',
```

A missing actor (no parameter, no authenticated user) throws `Hasyirin\Actor\Exceptions\MissingActorException`.

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Credits

- [Hasyirin Fakhriy](https://github.com/hasyirin)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). See [License File](LICENSE.md).
