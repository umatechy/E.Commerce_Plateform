<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Events\Support\RecordsOutboxEvents;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\StoreTeamService;
use App\Domain\Packages\Models\Package;
use App\Domain\Packages\Services\SubscriptionLifecycleService;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use App\Domain\Tenancy\Support\BusinessCategories;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Phase B44 — Module 03 §5–6, §23, §26, §55–56, owner decision 13: the one
 * place a store comes into existence, for both creation models:
 *
 *   self-service  the customer signs up: they become the owner at once;
 *   platform      Umar Techy staff create the store for a customer: the
 *                 owner gets an invitation by email and becomes the owner
 *                 when they accept it (Module 03 §26 "assisted
 *                 provisioning": until then the store has no owner and
 *                 cannot be launched, because nobody can sign in to it).
 *
 * Steps (§55): validate → create the store (StoreObserver creates the
 * default roles, warehouse, shipping zone, order numbering and secrets —
 * §23) → owner membership or owner invitation → trial subscription on the
 * chosen or the platform's trial package (§9 entitlements) → status
 * pending_setup ("onboarding") → audit + outbox event.
 *
 * Everything runs in one transaction (§56): a store whose provisioning
 * fails is never left behind half made. The invitation email is queued in
 * the same transaction and only goes out if the store exists. Staff
 * requests are idempotent by key for 24 hours (§55), so a double click
 * never creates two stores.
 */
final class StoreProvisioningService
{
    public const VIA_SELF_SERVICE = 'self_service';
    public const VIA_PLATFORM = 'platform';

    public function __construct(
        private readonly SubscriptionLifecycleService $subscriptions,
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly RecordsOutboxEvents $outbox,
    ) {}

    /**
     * A customer's own sign-up: they are the owner from the first moment.
     * $package and $slugBase are for platform tooling (the demo store);
     * sign-up uses the platform's trial package and the store's name.
     */
    public function selfService(User $owner, string $storeName, ?string $businessCategory, ?Package $package = null, ?string $slugBase = null, bool $starterTemplate = false): Store
    {
        return DB::transaction(function () use ($owner, $storeName, $businessCategory, $package, $slugBase, $starterTemplate) {
            $store = $this->createStore($storeName, $businessCategory, self::VIA_SELF_SERVICE, $owner->id, $slugBase);
            $this->attachOwner($store, $owner);
            $this->subscriptions->startTrial($store, $package);
            $template = $starterTemplate ? $this->applyStarterTemplate($store, $owner) : null;
            $this->record($store, $owner, ['via' => self::VIA_SELF_SERVICE, 'starter_template' => $template]);

            return $store;
        });
    }

    /**
     * Umar Techy staff create a store for a customer (Module 03 §5A, §38).
     *
     * @param array{store_name: string, business_category: string, package_code: string, trial_days?: ?int, owner_email: string, idempotency_key?: ?string, starter_template?: bool} $data
     */
    public function forCustomer(User $staff, array $data): Store
    {
        $key = isset($data['idempotency_key']) && $data['idempotency_key'] !== '' ? 'store-provision:'.$staff->id.':'.$data['idempotency_key'] : null;
        if ($key !== null && ($existing = Cache::get($key)) !== null) {
            return Store::query()->findOrFail((int) $existing);
        }

        $package = Package::query()->where('code', $data['package_code'])->where('is_active', true)->first()
            ?? throw ValidationException::withMessages(['package_code' => 'Choose an active package.']);
        $email = Str::lower(trim($data['owner_email']));

        $store = DB::transaction(function () use ($staff, $data, $package, $email) {
            $store = $this->createStore($data['store_name'], $data['business_category'], self::VIA_PLATFORM, $staff->id);
            $this->subscriptions->startTrial($store, $package, $data['trial_days'] ?? null);
            // The invitation and its email belong to the new store.
            $this->tenant->asStore($store->id, fn () => app(StoreTeamService::class)->inviteOwner($store, $email, $staff));
            $template = ($data['starter_template'] ?? false) ? $this->applyStarterTemplate($store, $staff) : null;
            $this->record($store, $staff, ['via' => self::VIA_PLATFORM, 'package' => $package->code, 'owner_email' => $email, 'starter_template' => $template]);

            return $store;
        });

        if ($key !== null) {
            Cache::put($key, $store->id, now()->addDay());
        }

        return $store;
    }

    private function createStore(string $name, ?string $businessCategory, string $via, ?int $createdBy, ?string $slugBase = null): Store
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['store_name' => 'Give the store a name of up to 255 characters.']);
        }
        if ($businessCategory !== null && ! in_array($businessCategory, BusinessCategories::keys(), true)) {
            throw ValidationException::withMessages(['business_category' => 'Choose what the store sells from the list.']);
        }

        return Store::query()->create([
            'name' => $name,
            'slug' => $this->uniqueSlug($slugBase ?? $name),
            'status' => StoreStatus::PendingSetup,
            'business_category' => $businessCategory,
            'created_via' => $via,
            'created_by_user_id' => $createdBy,
        ]);
    }

    /**
     * Phase B45 (Module 03 §58, Module 07 §105): the starter template of the
     * store's business category — categories, attributes, filters, the
     * suggested theme the package includes and the default order. Inside
     * the provisioning transaction: a store is never left half dressed.
     * Brands are left to the owner (they choose them in the catalogue).
     */
    private function applyStarterTemplate(Store $store, User $actor): ?string
    {
        $key = \App\Domain\Catalog\Support\StarterTemplates::forBusinessCategory($store->business_category);
        if ($key !== null) {
            $this->tenant->asStore($store->id, fn () => app(\App\Domain\Catalog\Services\StarterTemplateService::class)
                ->apply($store, $key, $actor, ['theme' => true, 'default_sort' => true]));
        }

        return $key;
    }

    private function attachOwner(Store $store, User $owner): void
    {
        $role = Role::query()->withoutTenantScope()->where('store_id', $store->id)->where('slug', 'owner')->firstOrFail();
        $store->users()->attach($owner, ['role_id' => $role->id, 'status' => 'active']);
    }

    /** @param array<string, mixed> $context */
    private function record(Store $store, User $actor, array $context): void
    {
        $this->audit->record('store.provisioned', [...$context, 'business_category' => $store->business_category], $store, $store->id, $actor);
        $this->outbox->recordEventFor($store->id, 'store.created', ['store_id' => $store->id, 'via' => $context['via']], "store:{$store->id}:created");
    }

    /** The name as a slug, plus a short random part: unique, and not guessable from other stores (§15). */
    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name) ?: 'store', 60, '');
        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Store::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
