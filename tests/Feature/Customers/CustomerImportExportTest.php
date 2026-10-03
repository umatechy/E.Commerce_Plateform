<?php

declare(strict_types=1);

namespace Tests\Feature\Customers;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase B32 (gap G7, Module 10 §47–50): customer import (preview, then
 * confirm; nothing written before) and export (CSV, formula-safe,
 * audited).
 */
final class CustomerImportExportTest extends TestCase
{
    use RefreshDatabase;

    private function openStore(): Store
    {
        $store = Store::factory()->create(['status' => 'active']);
        app(TenantContext::class)->resolveToStore($store->id);

        return $store;
    }

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    private function registered(Store $store, array $attributes = []): Customer
    {
        return Customer::factory()->for($store)->create(['password' => Hash::make('correct-horse-99'), ...$attributes]);
    }

    public function test_import_previews_then_creates_only_on_confirmation_and_skips_existing(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $this->registered($store, ['email' => 'taken@example.com']);
        $this->actingAs($owner)->postJson('/api/v1/customer-groups', ['name' => 'Retail'])->assertCreated();

        $csv = "\xEF\xBB\xBFname,email,phone,group,tags\n"
            ."Ayesha Khan,ayesha@example.com,+923001234567,Retail,VIP;Lahore\n"
            ."Taken Person,taken@example.com,,,\n"
            ."No Email,,,,\n"
            ."Wrong Group,wg@example.com,,Wholesale,\n"
            ."Ayesha Again,AYESHA@example.com,,,\n";
        $preview = $this->actingAs($owner)->post('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('customers.csv', $csv)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.totals', ['rows' => 5, 'ready' => 1, 'existing' => 1, 'invalid' => 3]);

        // Nothing is written by the preview.
        $this->assertSame(0, Customer::query()->withoutTenantScope()->where('email', 'ayesha@example.com')->count());

        $this->actingAs($owner)->postJson('/api/v1/customers/import/'.$preview->json('data.id').'/confirm')->assertOk()->assertJsonPath('data', ['created' => 1, 'skipped' => 0]);
        $ayesha = Customer::query()->withoutTenantScope()->where('email', 'ayesha@example.com')->sole();
        $this->assertSame('import', $ayesha->source?->value);
        $this->assertSame('Retail', $ayesha->group?->name);
        $this->assertEqualsCanonicalizing(['VIP', 'Lahore'], $ayesha->tags()->pluck('name')->all());

        // A confirmed import cannot be run twice.
        $this->actingAs($owner)->postJson('/api/v1/customers/import/'.$preview->json('data.id').'/confirm')->assertStatus(410);
        $this->assertSame(1, AuditLog::query()->where('action', 'customers.imported')->count());
    }

    public function test_another_staff_member_cannot_confirm_my_import(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $colleague = $this->owner($store);

        $preview = $this->actingAs($owner)->post('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "name,email\nA,a@example.com\n")], ['Accept' => 'application/json'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($colleague)->postJson('/api/v1/customers/import/'.$preview->json('data.id').'/confirm')->assertStatus(410);
    }

    public function test_a_file_without_name_and_email_columns_is_refused(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);

        $this->actingAs($owner)->post('/api/v1/customers/import', ['file' => UploadedFile::fake()->createWithContent('c.csv', "first,last\nA,B\n")], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('code', 'bad_header');
    }

    public function test_browsing_the_list_does_not_use_up_the_export_limit(): void
    {
        // Found in the B32 browser check: the export's 5-per-10-minutes limit
        // shared its counter with the list's, so an export after a few
        // searches answered 429. Each limit now has its own key.
        $store = $this->openStore();
        $owner = $this->owner($store);

        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($owner)->getJson('/api/v1/customers?search='.$i)->assertOk();
        }
        $this->actingAs($owner)->get('/api/v1/customers/export')->assertOk();
    }

    public function test_the_export_is_csv_defuses_formulas_and_is_audited(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);
        $this->registered($store, ['name' => '=HYPERLINK("http://evil.example")', 'email' => 'x@example.com']);

        $response = $this->actingAs($owner)->get('/api/v1/customers/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFid,name,email", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertSame(1, AuditLog::query()->where('action', 'customers.exported')->count());
    }
}
