<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/** Who receives a store's invoices: its active Owner. */
final class BillingContact
{
    public function ownerOf(int $storeId): ?User
    {
        $userId = DB::table('store_user')
            ->join('roles', 'roles.id', '=', 'store_user.role_id')
            ->where('store_user.store_id', $storeId)
            ->where('store_user.status', 'active')
            ->where('roles.slug', 'owner')
            ->orderBy('store_user.user_id')
            ->value('store_user.user_id');

        return $userId !== null ? User::query()->find($userId) : null;
    }
}
