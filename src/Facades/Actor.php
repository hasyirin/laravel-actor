<?php

namespace Hasyirin\Actor\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Hasyirin\Actor\Actor
 */
class Actor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hasyirin\Actor\Actor::class;
    }
}
