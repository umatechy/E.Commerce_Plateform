<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

/** Module 15 §8 "Campaign Audience" — only the 2 types B10 supports (see inspection findings). */
enum CampaignAudienceType: string
{
    case AllCustomers = 'all_customers';
    case Segment = 'segment';
}
