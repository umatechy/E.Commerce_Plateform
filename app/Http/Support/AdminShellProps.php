<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\SystemRoles;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Settings\Services\ConfigService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;

/**
 * What the admin shell needs to draw itself (Phase B31, gap G6): the
 * signed-in user's permissions in the active store, the package's
 * features, the stores the user may switch to, and the store currency.
 *
 * DISPLAY ONLY. The navigation uses these to hide what a user may not
 * open and to mark what the package does not include. Nothing here is an
 * authorization decision: every API call is checked again by its policy
 * and by EntitlementService against the database.
 */
final class AdminShellProps
{
    public function __construct(
        private readonly ConfigService $config,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, mixed> */
    public function for(?User $user, ?int $activeStoreId): array
    {
        if ($user === null) {
            return ['permissions' => [], 'is_owner' => false, 'features' => [], 'package' => null, 'stores' => [], 'currency' => null, 'currencies' => []];
        }

        $stores = $user->stores()->wherePivot('status', 'active')->orderBy('stores.name')->get(['stores.id', 'stores.name']);
        $role = $activeStoreId === null ? null : $this->role($user, $activeStoreId);

        return [
            'permissions' => $role?->permissions->pluck('key')->values()->all() ?? [],
            'is_owner' => $role?->slug === SystemRoles::OWNER,
            ...$this->package($activeStoreId),
            'stores' => $stores->map(fn (Store $store) => ['id' => $store->id, 'name' => $store->name])->values()->all(),
            'currency' => $this->context->hasStore() ? (string) $this->config->get('store.default_currency') : null,
            // The currencies the platform offers (owner decision 2026-10-03), for the currency pickers.
            'currencies' => \App\Domain\Settings\Services\Currencies::supported(),
        ];
    }

    private function role(User $user, int $storeId): ?Role
    {
        $roleId = $user->stores()->wherePivot('store_id', $storeId)->wherePivot('status', 'active')->value('store_user.role_id');

        return $roleId === null ? null : Role::query()->withoutTenantScope()->with('permissions')->find($roleId);
    }

    /** @return array{features: array<string, bool>, package: array{code: string, name: string}|null} */
    private function package(?int $storeId): array
    {
        $subscription = $storeId === null
            ? null
            : Store::query()->withoutGlobalScopes()->find($storeId)?->currentSubscription()->withoutTenantScope()->with('package.entitlements')->first();
        $package = $subscription?->package;

        if ($package === null) {
            return ['features' => [], 'package' => null];
        }

        $features = [];
        foreach ($package->entitlements as $entitlement) {
            if ($entitlement->type === EntitlementType::Feature) {
                $features[$entitlement->key] = (bool) $entitlement->boolean_value;
            }
        }

        return ['features' => $features, 'package' => ['code' => $package->code, 'name' => $package->name]];
    }
}
