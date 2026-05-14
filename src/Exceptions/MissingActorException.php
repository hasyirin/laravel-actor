<?php

namespace Hasyirin\Actor\Exceptions;

use RuntimeException;

class MissingActorException extends RuntimeException
{
    public static function noAuthenticatedUser(): self
    {
        return new self('No actor was provided and no authenticated user is available to set as actor.');
    }
}
