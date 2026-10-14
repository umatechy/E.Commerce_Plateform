<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Console;

use App\Domain\Catalog\Models\Product;
use App\Domain\DeveloperPlatform\Models\ApplicationStatus;
use App\Domain\DeveloperPlatform\Models\DeveloperApplication;
use App\Domain\DeveloperPlatform\Services\ApiKeyService;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Phase B53 (SRS TEST-009; Module 31 §65–66) — the data the end-to-end
 * tests (tests/e2e, Playwright) run against: a launched demo store, its
 * owner with two-step sign-in set up, a customer account and a read-only
 * API key. Fresh random passwords, MFA secret and key on every run, written
 * only to storage/app/e2e/credentials.json (outside Git) for the tests to
 * read. Refuses to run in production.
 */
final class PrepareEndToEndCommand extends Command
{
    public const FILE = 'e2e/credentials.json';

    private const OWNER = 'e2e.owner@example.test';

    private const CUSTOMER = 'e2e.customer@example.test';

    protected $signature = 'e2e:prepare';

    protected $description = 'Create or refresh the demo store and accounts the end-to-end tests use (not in production).';

    public function handle(TenantContext $context, ApiKeyService $keys, Google2FA $engine): int
    {
        if (app()->environment('production')) {
            $this->error('Not in production.');

            return self::FAILURE;
        }
        if (! User::query()->where('email', self::OWNER)->exists()) {
            $this->call('demo:store', ['--email' => self::OWNER, '--customer-email' => self::CUSTOMER, '--name' => 'E2E Shop', '--no-pictures' => true]);
        }
        $owner = User::query()->where('email', self::OWNER)->firstOrFail();
        $store = Store::query()->where('name', 'E2E Shop')->firstOrFail();
        $context->resolveToStore($store->id);

        $ownerPassword = 'Own-'.Str::random(20).'-e2e';
        $secret = $engine->generateSecretKey(32);
        $owner->forceFill(['password' => $ownerPassword, 'mfa_secret' => $secret, 'mfa_confirmed_at' => now(), 'mfa_last_used_step' => null, 'is_active' => true])->save();
        $customerPassword = 'Cus-'.Str::random(20).'-e2e';
        Customer::query()->where('email', self::CUSTOMER)->firstOrFail()->forceFill(['password' => $customerPassword])->save();

        $application = DeveloperApplication::query()->firstOrCreate(['name' => 'E2E integration'], ['status' => ApplicationStatus::Active]);
        $apiKey = $keys->issue($application, ['products:read', 'orders:read'])['plaintext'];
        $product = Product::query()->where('status', 'active')->orderBy('id')->firstOrFail();

        $path = storage_path('app/'.self::FILE);
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, json_encode([
            'shop' => '/shop/'.$store->slug,
            'store_name' => $store->name,
            'product' => ['slug' => $product->slug, 'name' => $product->name],
            'owner' => ['email' => self::OWNER, 'password' => $ownerPassword, 'mfa_secret' => $secret],
            'customer' => ['email' => self::CUSTOMER, 'password' => $customerPassword],
            'api_key' => $apiKey,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @chmod($path, 0600);
        $this->info('End-to-end data ready: storage/app/'.self::FILE);

        return self::SUCCESS;
    }
}
