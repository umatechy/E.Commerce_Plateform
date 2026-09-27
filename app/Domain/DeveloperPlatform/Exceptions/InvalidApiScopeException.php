<?php

declare(strict_types=1);

namespace App\Domain\DeveloperPlatform\Exceptions;

use RuntimeException;

final class InvalidApiScopeException extends RuntimeException
{
    public function __construct(string $scope)
    {
        parent::__construct("\"{$scope}\" is not a recognized API scope.");
    }
}
