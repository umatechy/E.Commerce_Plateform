<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

/** Phase B40: a product import that cannot go on (file, expiry) or a row that cannot be processed. */
final class CatalogImportException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
