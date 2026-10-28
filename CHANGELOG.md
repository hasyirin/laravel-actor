# Changelog

All notable changes to `laravel-actor` will be documented in this file.

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
php artisan vendor:publish --tag="laravel-actor-migrations"
php artisan migrate

```
See the [README](https://github.com/hasyirin/laravel-actor/blob/main/README.md) for full usage and configuration.
