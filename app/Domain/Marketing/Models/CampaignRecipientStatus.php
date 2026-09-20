<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Models;

/** Module 15 §15/§55 — B10's own boundary-only scope (see inspection findings "Campaign Execution Is Boundary-Only"). */
enum CampaignRecipientStatus: string
{
    case Queued = 'queued'; // handed off to the (future) Module 21 boundary — never means "delivered"
    case SkippedNoConsent = 'skipped_no_consent';
    case SkippedFrequency = 'skipped_frequency';
}
