<?php

declare(strict_types=1);

namespace App\Domain\Help\Policies;

use App\Domain\Help\Support\HelpCatalog;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Phase B54 (Module 34 §15 "role-aware help", §62 "search security",
 * §73.3 "never create a second authorization model") — which articles a
 * reader may open, from the roles the platform already has:
 *
 * - a shopper on a storefront: customer help only;
 * - store staff: store help (merchant) and customer help;
 * - the store's Owner: also owner-only help;
 * - staff who may see the store's developer settings, and the Owner:
 *   also developer help;
 * - Umar Techy staff: everything, including internal runbooks.
 *
 * Every list, search, page help and article read is filtered with this
 * on the server before anything is returned, so an article a reader may
 * not open never shows, not even its title (§62.5).
 */
final class HelpAudiencePolicy extends BaseTenantPolicy
{
    /** @return list<string> */
    public function audiencesFor(?User $user): array
    {
        if ($user === null) {
            return ['customer'];
        }
        if ($user->isPlatformStaff()) {
            return array_keys(HelpCatalog::AUDIENCES);
        }
        $audiences = ['merchant', 'customer'];
        $owner = $this->isOwner($user);
        if ($owner) {
            $audiences[] = 'owner';
        }
        if ($owner || $this->userHasPermission($user, 'developer_platform.view')) {
            $audiences[] = 'developer';
        }

        return $audiences;
    }
}
