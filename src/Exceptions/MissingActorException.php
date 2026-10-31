<?php

namespace Hasyirin\Actor\Exceptions;

use RuntimeException;

class MissingActorException extends RuntimeException
{
    public static function noAuthenticatedUser(): self
    {
        return new self('No actor was provided and no authenticated user is available to set as actor.');
    }

    public static function authenticatedUserIsNotAModel(mixed $user): self
    {
        return new self('The authenticated user ['.get_debug_type($user).'] is not an Eloquent model, so it cannot be set as actor.');
    }
}
