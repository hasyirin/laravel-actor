# Changelog

All notable changes to `laravel-actor` will be documented in this file.

## v2.2.0 - 2026-11-05

### Changed

- Requires `spatie/laravel-package-tools` 1.93 or later (was 1.16).
- A repeat act no longer eager-loads the `$with` relations of the action model it returns. They load when you first use them, also with `Model::preventLazyLoading()`.
- `act()` keeps a model of another class with the same `name` in a loaded `actions` relation, and adds its row after it. Before, it replaced that model.
- The `Action` model declares the types of its columns with `@property` tags. If your custom action model casts a column, for example `name` to an enum, declare that `@property` on your model, so PHPStan sees the cast type.
- `actor()` and `resource()` return `MorphTo<Model, $this>`, and the scopes use `Builder<static>`. An override of `actor()` or `resource()` that declares a narrower type, such as `MorphTo<User, $this>`, now fails PHPStan's `method.childReturnType` check.
- Dev dependencies require their current releases, and PHPStan checks the package at level 10.

### Fixed

- On an action model that eager-loads `actor` through `$with`, a repeat act now gives `saving` observers and the returned model the new actor. Before, the old actor stayed loaded, so an observer that copied data from `$action->actor` saved the old actor's data.
- The model that a first act returns stays on the connection that saved it. Before, a model without its own `$connection` followed `database.default`, so it switched connections when that setting changed.
- When an int-backed enum cast stored a loaded action's name as an int, `act()` now replaces that action in the loaded `actions` relation. Before, it added a second copy.
- `act()`, `action()` and `acted()` no longer throw a `TypeError` when a loaded `actions` relation holds an item that is not a model.
- `action()` and `acted()` skip a loaded model of another class with the same `name`. Before, they threw a `TypeError`.

## v2.1.0 - 2026-11-02

### Changed

- `act()` saves through Eloquent again, under a row lock, instead of with a native upsert. Model events fire (`updating` and `updated` only when a column changes), casts and mutators apply to every column except `resource_type`, `resource_id` and `name`, and `wasRecentlyCreated` is `true` on a first act. Observers run inside the transaction of `act()`, and they run again if `act()` retries.
- `ActionRecorded` listeners can read `wasRecentlyCreated`, `getChanges()` and `getPrevious()` on `$event->action`, unless they are queued.
- If a `saving`, `creating` or `updating` listener returns `false`, `act()` writes nothing, does not dispatch `ActionRecorded`, and returns the unsaved model.
- A repeat act no longer uses up an auto-increment ID.
- On a soft-deleted row, `act()` throws `UniqueConstraintViolationException` instead of `ModelNotFoundException`. `SoftDeletes` is still not supported.
- On PostgreSQL at `REPEATABLE READ` or `SERIALIZABLE`, a first act that loses a race inside your own transaction throws `UniqueConstraintViolationException` instead of `DeadlockException`.

### Fixed

- A custom action model can keep an extra column in sync with the actor again. In v2.0.0, `saving` observers never ran, so a repeat act left the new actor with the old value, and a required column made every `act()` fail. An observer now runs on first acts and repeat acts, and `act()` saves the column with the actor.

## v2.0.0 - 2026-11-01

### Breaking changes

- Requires Laravel 13.39 or a later 13.x release, because older releases can fire `ActionRecorded` twice when a COMMIT fails. Laravel 12 is no longer supported.
- `act()` writes with a native upsert, not through a model save. It fires no Eloquent write events, and the casts and mutators of a custom action model do not apply to the write. Listen to the new `ActionRecorded` event instead. `wasRecentlyCreated` is always `false` on the returned model.
- `act()` runs in a short transaction. Inside your own transaction, a concurrent `act()` can make your transaction fail with a `DeadlockException`. Run your transaction with more attempts, for example `DB::transaction($callback, 3)`.
- The migration stub is renamed to `create_actor_actions_table`.
- The unique index is named after the table: `{prefix}{table}_resource_name_unique`. The table prefix is part of the name when the connection has `prefix_indexes` on.
- On MySQL and MariaDB, the `name` column uses the `utf8mb4_bin` collation, so action names match exactly, including case and accents. Queries still ignore trailing spaces.
- Actions use the default database connection, even when the resource model uses another connection. To use another connection, set `$connection` on a custom action model.
- `Actor::act()` and `InteractsWithActions::act()` accept any `DateTimeInterface` as `$at` (was `Illuminate\Support\Carbon`).
- `InteractsWithActions::acted()` queries through the model's `actions()` relation instead of `Actor::acted()`. If your tests mock the `Actor` facade, they no longer see this call.

### Added

- `Hasyirin\Actor\Events\ActionRecorded`. `act()` dispatches it after it saves the row. If a transaction is open on the action's connection, the event waits until that transaction commits. It does not fire if that transaction rolls back. A transaction on another connection does not delay it.

### Fixed

- Two concurrent `act()` calls for the same resource and name no longer save a row that mixes both calls, for example the actor type of one call and the actor ID of the other.
- Concurrent first `act()` calls for the same resource and name no longer fail with a unique constraint violation. Laravel runs `act()` again on a deadlock, 3 attempts in total.
- Int-backed enum names are stored and compared as strings. On MySQL/MariaDB, a value such as `0` no longer matches other action names, and `act()` no longer overwrites them.
- `act()` replaces or adds its row in a loaded `actions` relation, so `action()` and `acted()` no longer return stale data.
- `InteractsWithActions::acted()` reads a loaded `actions` relation instead of running a query.
- `action()` and the `actions` relation read the same connection that `act()` writes to.
- A model attribute named `actions` no longer hides the loaded relation in `action()`.
- `action()` compares names exactly against a loaded relation.
- Actions created through Eloquent without `acted_at` get the current time on the model.
- An authenticated user that is not an Eloquent model throws `MissingActorException` instead of an `Error`.
- The documented publish tags are `actor-migrations` and `actor-config`.
- CI runs the tests when only the migration stub changes, and also runs them on MySQL, MariaDB and PostgreSQL.

## v1.1.1 - 2026-10-28

### Changed

- Dev dependencies now allow Pest 5 (PHPUnit 13): `pestphp/pest`, `pestphp/pest-plugin-arch`, and `pestphp/pest-plugin-laravel` accept `^3.0||^4.0||^5.0`.
- CI workflows use `actions/checkout` v7 instead of v6.

No changes to runtime code or `require` constraints.

## v1.1.0 - 2026-05-21

### Added

- `Actor::act()`, `Actor::findAction()`, and `Actor::acted()` now accept a `BackedEnum` in addition to a `string` for the action name. The enum's `value` is used for storage and lookup.
- `InteractsWithActions::act()`, `acted()`, and `action()` accept the same `BackedEnum|string` union for symmetry.
- `Action::scopeOfName()` accepts `BackedEnum|string`.

Existing `string` callers are unaffected.

### Upgrade note

If you extend `Hasyirin\Actor\Actor` or `Hasyirin\Actor\Models\Action` and override `act()`, `findAction()`, `acted()`, or `scopeOfName()`, change the name parameter type to `BackedEnum|string`. Otherwise PHP stops with "Declaration ... must be compatible".

## v1.0.1 - 2026-05-14

### Fixed

- Migration stub now reads the actions table name from `config('actor.tables.actions')` in both `up()` and `down()`. Previously hardcoded to `'actions'`, which mismatched `Action::getTable()` if a consumer customized the table name in config.

## v1.0.0 - 2026-05-14

First tagged release of laravel-actor — a polymorphic action log for Eloquent models.

### Features

- **`Actor` facade** — `act()`, `acted()`, `findAction()`. All read methods accept an optional `?Model $actor` to scope by who.
- **`InteractsWithActions` trait** — `act()`, `acted()`, `action()`, `actions()` relation. `action()` reads from the loaded relation when present (no extra query).
- **Latest-state storage** — at most one row per `(resource, name)`; re-acting overwrites the actor and `acted_at`. Enforced by a unique composite index.
- **Configurable** via `config/actor.php`: action model class, table name, and auth guard for the `auth()->user()` fallback.
- **Domain exception** — `MissingActorException` when no actor is provided and no user is authenticated.

### Requirements

- PHP **8.4** or higher
- Laravel **12** or **13**

### Install

```bash
composer require hasyirin/laravel-actor
php artisan vendor:publish --tag="actor-migrations"
php artisan migrate

```
See the [README](https://github.com/hasyirin/laravel-actor/blob/main/README.md) for full usage and configuration.
