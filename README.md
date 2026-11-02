# Laravel Actor

[![Latest Version on Packagist](https://img.shields.io/packagist/v/hasyirin/laravel-actor.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-actor)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-actor/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/hasyirin/laravel-actor/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/hasyirin/laravel-actor/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/hasyirin/laravel-actor/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/hasyirin/laravel-actor.svg?style=flat-square)](https://packagist.org/packages/hasyirin/laravel-actor)

A small polymorphic action log for Eloquent models. Record that an actor (any model — typically a `User`) performed a named action on a resource (any model), with the time it happened. Each `(resource, name)` pair holds a single latest-state row.

## Installation

The package requires PHP 8.4 or later, and Laravel 13.39 or a later 13.x release. It is tested on SQLite, MySQL, MariaDB and PostgreSQL, but not on SQL Server.

```bash
composer require hasyirin/laravel-actor
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag="actor-migrations"
php artisan migrate
```

The migration uses `morphs()` for `resource_id` and `actor_id`. These columns are integers, unless your app calls `Schema::morphUsingUuids()` or `Schema::morphUsingUlids()`. Each column must fit the keys of every model that you store in it. If it does not, change `morphs('resource')` or `morphs('actor')` in the published migration before you run it, for example to `uuidMorphs()` or `ulidMorphs()`.

Optionally publish the config:

```bash
php artisan vendor:publish --tag="actor-config"
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

`act()` creates or updates the row for `(resource_type, resource_id, name)`. Re-acting the same action on the same resource overwrites the actor and timestamp — there is only ever one row per `(resource, name)`. If you need a full event history, this package is not the right choice.

### Events

`act()` dispatches `Hasyirin\Actor\Events\ActionRecorded` after it saves the row. Listen to this event to react to an action:

```php
use Hasyirin\Actor\Events\ActionRecorded;

Event::listen(function (ActionRecorded $event) {
    $event->action->name;
    $event->action->actor;
    $event->action->resource;
});
```

- If a transaction is open on the action's connection, the event waits until that transaction commits. If that transaction rolls back, the event does not fire. A retry does not fire it twice.
- A transaction on another connection does not delay the event, so a listener can run before that transaction commits.
- The event fires for first acts and repeat acts. `$event->action->wasRecentlyCreated` tells you which one it was, and `getChanges()` and `getPrevious()` show what a repeat act changed. They are empty on a first act. A queued listener gets a reloaded model, which has none of this.
- A listener cannot cancel an act, because the row is already saved. If a listener throws, `act()` throws too, and a loaded `actions` relation does not show the new row.

`act()` saves the row through Eloquent. Model events fire (`saving`, `creating` or `updating`, `created` or `updated`, `saved`), and casts and mutators apply to every column except `resource_type`, `resource_id` and `name`. `act()` finds the row by these three columns, so it writes them as given. `updating` and `updated` fire only when a column changes: a repeat act that changes nothing, such as the same actor in the same second, fires only `saving` and `saved`, and still dispatches `ActionRecorded`.

Model observers and `ActionRecorded` listeners run at different times:

- Model observers run inside the transaction of `act()`, before the commit. Their database writes commit with the action, and an observer that throws rolls the action back. Until the commit, other processes cannot see the changes. A slow observer holds the row lock. On SQLite, it holds the write lock of the whole database. If `act()` runs again after a deadlock, or after a first act that lost a race, the observers run again.
- `ActionRecorded` listeners run once, after the commit.

Put work that the database cannot roll back, such as emails, HTTP calls and cache updates, in an `ActionRecorded` listener. A job on a queue outside this database, such as Redis or SQS, counts too, unless you dispatch it with `afterCommit()`. Otherwise it can start before the commit. It then finds no row for a first act, or the old values for a repeat act.

You can also implement `Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit` on an observer. Laravel then runs its `created`, `updated` and `saved` methods once, after the commit. It still runs `saving`, `creating` and `updating` inside the transaction, on each attempt, so a `saving` observer that sets a column keeps working.

If a `saving`, `creating` or `updating` listener returns `false`, `act()` writes nothing and does not dispatch `ActionRecorded`. It returns the unsaved model and leaves a loaded `actions` relation alone.

### Concurrency

You can call `act()` from concurrent requests:

- `act()` locks the row and saves it, in a short transaction. When two calls for the same resource and name run at the same time, the row gets the values of one call, never a mix of both. This includes columns that a `saving` observer sets. The last call to commit wins.
- When the transaction fails with a deadlock or a serialization failure, Laravel runs it again, 3 attempts in total. A lock wait timeout counts too, so a row that another session keeps locked can make `act()` wait 3 times.
- A first act that loses the race to another first act runs again and updates the row, 3 attempts in total.
- A concurrent delete waits until `act()` commits. The returned model can then describe a row that no longer exists.
- A tight loop of deletes on the same rows can use up these attempts. `act()` then throws the last database error.

If you call `act()` inside your own transaction, Laravel cannot run `act()` again on its own. A concurrent `act()` for the same resource and name can then make your transaction fail with a `DeadlockException`. Give your transaction more attempts, so that Laravel runs all of it again. A retry does not roll back the state of your models, so load them inside the closure:

```php
DB::transaction(function () use ($id) {
    $post = Post::findOrFail($id);

    $post->update(['status' => 'approved']);
    $post->act('approved');
}, 3);
```

Two setups need more care:

- On PostgreSQL at `REPEATABLE READ` or `SERIALIZABLE`, Laravel does not retry this failure. `act()` throws a `DeadlockException` on a repeat act and a `UniqueConstraintViolationException` on a first act. Catch the exception and run your transaction again yourself.
- On SQLite, if your transaction reads first and then calls `act()`, it can fail with "database is locked" while another process writes. Set `'transaction_mode' => 'IMMEDIATE'` on the connection, or call `act()` outside the transaction.

### Loaded relations

When the `actions` relation is loaded, `action()` and `acted()` read it instead of running a query. Otherwise they query through the model's `actions()` relation. `Actor::acted()` and `Actor::findAction()` query the action model directly, so an `actions()` override does not change them.

On a model that uses the trait, `act()` replaces or adds its row in the loaded relation, so `action()` and `acted()` do not return stale data. It does this at once, even inside a transaction. If your transaction rolls back, reload the relation. A replaced row has no nested eager loads, such as `actions.actor`. If you eager-load `actions` with constraints, `action()` and `acted()` see only the loaded rows and the rows that `act()` added.

### Action names

Action names match exactly, including case and accents. On MySQL and MariaDB, the migration gives the `name` column the `utf8mb4_bin` collation, so queries match names in the same way as PostgreSQL, SQLite and loaded relations. There is one exception: `utf8mb4_bin` ignores trailing spaces, so `'approved '` and `'approved'` are the same name in queries on MySQL and MariaDB. A loaded relation still matches exactly, so `action()` and `acted()` treat them as different names once `actions` is loaded. The default collation of SQL Server is case-insensitive. If you use SQL Server, set a case-sensitive collation on the `name` column in the published migration.

### Custom action models

Set `models.action` in the config to use your own model. The model must extend `Hasyirin\Actor\Models\Action`. Its table must keep the unique index on `(resource_type, resource_id, name)`, because the index keeps one row per action when first acts race. `SoftDeletes` is not supported: `act()` throws a `UniqueConstraintViolationException` when the row is soft-deleted.

To keep an extra column in sync with the actor, set it in a `saving` observer. The observer runs on first acts and repeat acts, and `act()` saves the column with the actor, so no reader sees the new actor with the old value. The column can be required.

```php
class Action extends \Hasyirin\Actor\Models\Action
{
    protected static function booted(): void
    {
        static::saving(function (Action $action) {
            $action->profile_id = $action->actor->current_profile_id;
        });
    }
}
```

### Database connection

Actions use the default database connection, even when the resource model uses another connection. To store them on another connection, set `$connection` on a custom action model (`models.action` in the config), and on the published migration.

Laravel runs some relation queries, such as `whereHas('actions')` and `withCount('actions')`, as subqueries on the resource's connection. These work only when the actions table is on that connection too. The `resource` and `actor` relations of an action use the action's connection, unless the related model sets `$connection`.

### Authentication guard

If the actor is resolved from `auth()`, the package uses Laravel's default guard. Override via config:

```php
// config/actor.php
'guard' => 'sanctum',
```

A missing actor (no parameter and no authenticated user), or an authenticated user that is not an Eloquent model, throws `Hasyirin\Actor\Exceptions\MissingActorException`.

## Testing

```bash
composer test
```

The tests use an in-memory SQLite database by default. To run them on another database, set `ACTOR_TEST_CONNECTION` to `mysql`, `mariadb` or `pgsql`, and set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD`. The tests drop all tables in that database. The race tests need the `pcntl` and `posix` extensions.

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Credits

- [Hasyirin Fakhriy](https://github.com/hasyirin)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). See [License File](LICENSE.md).
