<?php

namespace Hasyirin\Actor\Commands;

use Illuminate\Console\Command;

class ActorCommand extends Command
{
    public $signature = 'laravel-actor';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
