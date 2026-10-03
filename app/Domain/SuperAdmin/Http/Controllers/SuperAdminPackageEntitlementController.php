<?php

declare(strict_types=1);

namespace App\Domain\SuperAdmin\Http\Controllers;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Packages\Http\Resources\PackageResource;
use App\Domain\Packages\Models\EntitlementType;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Models\PackageEntitlement;
use App\Domain\Packages\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Module 04 §63 "Enable / Disable Feature", "Set Limits", "View
 * Entitlement History" (Phase B32): platform staff change what a package
 * includes. Behind platform MFA and step-up (routes/api_v1.php).
 *
 * - Only keys the platform already knows (a key some package has) can
 *   be set, with that key's type: no new feature name is invented here.
 * - The change applies to every store on the package at once: their
 *   cached entitlement answers are dropped. Usage above a lowered limit
 *   is not deleted (Module 04 §20); the limit applies to new usage.
 * - The audit entry holds every key's before and after: that is the
 *   entitlement history.
 */
final class SuperAdminPackageEntitlementController
{
    public function update(Request $request, Package $package, AuditLogger $audit): PackageResource
    {
        \Illuminate\Support\Facades\Gate::forUser($request->user())->authorize('manage', Package::class);

        $data = $request->validate([
            'entitlements' => ['required', 'array', 'min:1', 'max:100'],
            'entitlements.*.key' => ['required', 'string', 'max:64', 'distinct'],
            'entitlements.*.enabled' => ['sometimes', 'boolean'],
            'entitlements.*.unlimited' => ['sometimes', 'boolean'],
            'entitlements.*.limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var \Illuminate\Support\Collection<string, PackageEntitlement> $known one row per key the platform knows, as a template */
        $known = PackageEntitlement::query()->orderBy('id')->get()->unique('key')->keyBy('key');
        $changes = [];

        DB::transaction(function () use ($data, $package, $known, &$changes) {
            foreach ($data['entitlements'] as $index => $item) {
                $template = $known->get($item['key']) ?? throw ValidationException::withMessages(["entitlements.{$index}.key" => "\"{$item['key']}\" is not a feature or limit the platform knows."]);
                $row = PackageEntitlement::query()->firstOrNew(['package_id' => $package->id, 'key' => $item['key']], [
                    'type' => $template->type, 'enforcement' => $template->enforcement, 'period' => $template->period,
                ]);
                $before = $row->exists ? $this->value($row) : 'not set';

                if ($template->type === EntitlementType::Feature) {
                    if (! array_key_exists('enabled', $item)) {
                        throw ValidationException::withMessages(["entitlements.{$index}.enabled" => "Say whether \"{$item['key']}\" is included."]);
                    }
                    $row->boolean_value = (bool) $item['enabled'];
                } else {
                    $unlimited = (bool) ($item['unlimited'] ?? false);
                    if (! $unlimited && ! isset($item['limit'])) {
                        throw ValidationException::withMessages(["entitlements.{$index}.limit" => "Give a limit for \"{$item['key']}\", or make it unlimited."]);
                    }
                    $row->is_unlimited = $unlimited;
                    $row->limit_value = $unlimited ? null : (int) $item['limit'];
                }

                $after = $this->value($row);
                if ($before !== $after) {
                    $row->save();
                    $changes[] = ['key' => $item['key'], 'before' => $before, 'after' => $after];
                }
            }
        });

        if ($changes !== []) {
            $storeIds = Subscription::query()->withoutTenantScope()->where('package_id', $package->id)->pluck('store_id')->unique();
            foreach ($storeIds as $storeId) {
                foreach ($changes as $change) {
                    Cache::forget("tenant:{$storeId}:entitlement:{$change['key']}");
                }
            }

            $audit->record('super_admin.package.entitlements_changed', [
                'acting_super_admin_id' => $request->user()->id,
                'package' => $package->code,
                'reason' => $data['reason'],
                'changes' => $changes,
                'stores_affected' => $storeIds->count(),
            ], $package);
        }

        return new PackageResource($package->load('entitlements'));
    }

    /** The meaning of a row as text, for the history: "yes", "no", "unlimited", "500" or "not set". */
    private function value(PackageEntitlement $row): string
    {
        return $row->type === EntitlementType::Feature
            ? ($row->boolean_value ? 'yes' : 'no')
            : ($row->is_unlimited ? 'unlimited' : (string) $row->limit_value);
    }
}
