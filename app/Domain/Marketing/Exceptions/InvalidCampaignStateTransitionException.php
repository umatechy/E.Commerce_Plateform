<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Exceptions;

use App\Domain\Marketing\Models\CampaignStatus;
use RuntimeException;

final class InvalidCampaignStateTransitionException extends RuntimeException
{
    public function __construct(public readonly CampaignStatus $from, public readonly CampaignStatus $to)
    {
        parent::__construct("Cannot transition campaign from [{$from->value}] to [{$to->value}].");
    }
}
