# Changelog

All notable changes to `laravel-actor` will be documented in this file.

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
