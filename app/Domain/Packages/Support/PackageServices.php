<?php

declare(strict_types=1);

namespace App\Domain\Packages\Support;

/**
 * The first wording of each standard package's services (owner decision
 * 2026-10-26). Used when a package is created by the seeder; platform staff
 * change them afterwards in Super Admin → Packages. The migration that added
 * the column keeps its own copy, as migrations must not change later.
 */
final class PackageServices
{
    /** @var array<string, list<string>> */
    public const DEFAULTS = [
        'basic' => [
            'Help centre and email support',
            'Daily encrypted backups of your store',
            'Secure HTTPS on your store address',
        ],
        'business' => [
            'Store setup with you, including your first 50 products',
            'Your own domain connected and secured for you',
            'A one-hour training session for you and your team',
            'Priority support on WhatsApp and email',
        ],
        'premium' => [
            'Everything in the Business services',
            'A dedicated WhatsApp support line',
            'Two hours of store design changes every month',
            'A monthly performance report with suggestions',
            'New features first',
        ],
    ];
}
