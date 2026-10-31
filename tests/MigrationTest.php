<?php

use Hasyirin\Actor\ActorServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

it('publishes the migration under a package-specific file name', function () {
    $targets = array_values(ServiceProvider::pathsToPublish(ActorServiceProvider::class, 'actor-migrations'));

    expect($targets)->toHaveCount(1)
        ->and($targets[0])->toEndWith('_create_actor_actions_table.php');
});

it('can create a second actions table in the same database', function () {
    config()->set('actor.tables.actions', 'audit_actions');

    $stub = array_key_first(ServiceProvider::pathsToPublish(ActorServiceProvider::class, 'actor-migrations'));
    (require $stub)->up();

    expect(Schema::hasTable('audit_actions'))->toBeTrue();
});

it('names the unique index after the table', function (string $table, string $index) {
    Schema::drop('actions');
    config()->set('actor.tables.actions', $table);

    $stub = array_key_first(ServiceProvider::pathsToPublish(ActorServiceProvider::class, 'actor-migrations'));
    (require $stub)->up();

    $unique = collect(Schema::getIndexes($table))->firstWhere('columns', ['resource_type', 'resource_id', 'name']);

    expect($unique['name'] ?? null)->toBe($index);
})->with([
    'the default table' => ['actions', 'actions_resource_name_unique'],
    'a 28-character table' => ['purchase_order_audit_actions', 'purchase_order_audit_actions_resource_name_unique'],
]);

it('names the unique index after a schema-qualified table', function () {
    DB::statement(match (DB::getDriverName()) {
        'sqlite' => "attach database ':memory:' as actor_test_audit",
        'pgsql' => 'create schema if not exists actor_test_audit',
        default => 'create database if not exists actor_test_audit',
    });
    Schema::dropIfExists('actor_test_audit.actions');
    config()->set('actor.tables.actions', 'actor_test_audit.actions');

    $stub = array_key_first(ServiceProvider::pathsToPublish(ActorServiceProvider::class, 'actor-migrations'));
    (require $stub)->up();

    $unique = collect(Schema::getIndexes('actor_test_audit.actions'))->firstWhere('columns', ['resource_type', 'resource_id', 'name']);

    DB::statement(match (DB::getDriverName()) {
        'sqlite' => 'detach database actor_test_audit',
        'pgsql' => 'drop schema actor_test_audit cascade',
        default => 'drop database actor_test_audit',
    });

    expect($unique['name'] ?? null)->toBe('actor_test_audit_actions_resource_name_unique');
});

it('adds the table prefix to the unique index name like Laravel does', function (bool $prefixIndexes, string $index) {
    Schema::drop('actions');
    $connection = DB::getDefaultConnection();
    config()->set("database.connections.$connection.prefix", 'app_');
    config()->set("database.connections.$connection.prefix_indexes", $prefixIndexes);
    DB::purge($connection);

    $stub = array_key_first(ServiceProvider::pathsToPublish(ActorServiceProvider::class, 'actor-migrations'));
    (require $stub)->up();

    $unique = collect(Schema::getIndexes('actions'))->firstWhere('columns', ['resource_type', 'resource_id', 'name']);

    expect($unique['name'] ?? null)->toBe($index);
})->with([
    'with prefix_indexes' => [true, 'app_actions_resource_name_unique'],
    'without prefix_indexes' => [false, 'actions_resource_name_unique'],
]);
