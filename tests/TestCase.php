<?php

namespace Hasyirin\Actor\Tests;

use Hasyirin\Actor\ActorServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Models\ExampleActor;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'Hasyirin\\Actor\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );

        $this->createFixtureTables();
        $this->runPackageMigration();
    }

    protected function getPackageProviders($app)
    {
        return [
            ActorServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('auth.providers.users.model', ExampleActor::class);
    }

    protected function createFixtureTables(): void
    {
        Schema::create('example_resources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('example_actors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function runPackageMigration(): void
    {
        $migration = require __DIR__.'/../database/migrations/create_actions_table.php.stub';
        $migration->up();
    }
}
