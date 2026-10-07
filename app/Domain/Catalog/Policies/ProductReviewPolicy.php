<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Catalog\Models\ProductReview;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\BaseTenantPolicy;

/**
 * Owner decision 15: reviews are moderated with reviews.manage (Owner,
 * Administrator, Manager, Content & Marketing).
 */
final class ProductReviewPolicy extends BaseTenantPolicy
{
    public function manage(User $user, ?ProductReview $review = null): bool
    {
        if ($review !== null && ! $this->belongsToUsersActiveStore($user, $review)) {
            return false;
        }

        return $this->userHasPermission($user, 'reviews.manage') || $this->isOwner($user);
    }
}
