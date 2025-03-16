<?php

namespace Hasyirin\Actor;

use Hasyirin\Actor\Commands\ActorCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ActorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-actor')
            ->hasConfigFile()
            ->hasViews()
            ->hasMigration('create_laravel_actor_table')
            ->hasCommand(ActorCommand::class);
    }
}
