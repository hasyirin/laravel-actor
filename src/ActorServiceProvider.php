<?php

namespace Hasyirin\Actor;

use Hasyirin\Actor\Commands\ActorCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

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
