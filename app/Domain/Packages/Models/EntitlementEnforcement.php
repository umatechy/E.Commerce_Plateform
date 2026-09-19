<?php

declare(strict_types=1);

namespace App\Domain\Packages\Models;

/**
 * Module 04 §10 "Hard Limit vs Soft Limit". Only meaningful for
 * EntitlementType::UsageLimit rows — feature flags are inherently
 * hard (on/off has no "soft" version).
 */
enum EntitlementEnforcement: string
{
    case Hard = 'hard'; // action is blocked once the limit is reached
    case Soft = 'soft'; // warning only; caller decides whether to allow temporarily
}
