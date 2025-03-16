<?php

namespace Hasyirin\Actor;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use Hasyirin\Actor\Commands\ActorCommand;

class ActorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-actor')
            ->hasConfigFile()
            ->hasMigrations([
                'create_actions_table',
            ]);
    }
}
