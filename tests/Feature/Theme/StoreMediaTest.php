<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Theme\Models\StoreMedia;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B37 — Module 17 §6–7: logo, favicon, banner and sharing images as
 * JPG/PNG uploads, re-encoded, kept per store, and only usable by the store
 * that uploaded them.
 */
final class StoreMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function owner(Store $store): User
    {
        $user = User::factory()->create();
        $store->users()->attach($user, ['role_id' => $this->systemRole($store, 'owner')->id, 'status' => 'active']);

        return $user;
    }

    private function png(int $w = 400, int $h = 120, string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    public function test_an_owner_uploads_a_png_or_jpg_logo_and_the_theme_uses_it(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);

        $png = $this->actingAs($owner)->post('/api/v1/store/media', ['purpose' => 'logo', 'file' => $this->png()], ['Accept' => 'application/json'])->assertCreated();
        $path = $png->json('data.path');
        $this->assertMatchesRegularExpression('#^/storage/stores/'.$store->public_id.'/media/[0-9a-z]{26}\.png$#', $path);
        Storage::disk('public')->assertExists(substr($path, strlen('/storage/')));
        $this->actingAs($owner)->post('/api/v1/store/media', ['purpose' => 'banner', 'file' => UploadedFile::fake()->image('hero.jpg', 1600, 800)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.width', 1600);

        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => [
            'branding' => ['logo_url' => $path, 'favicon_url' => 'https://cdn.example.com/icon.png'],
            'sections' => [['type' => 'hero', 'config' => ['image_url' => $path]]],
        ]])->assertOk()->assertJsonPath('data.draft_config.branding.logo_url', $path);
        $this->assertSame(2, StoreMedia::query()->withoutTenantScope()->where('store_id', $store->id)->count());
    }

    public function test_only_jpg_and_png_images_of_sensible_size_are_taken(): void
    {
        $store = Store::factory()->create();
        $owner = $this->owner($store);
        $send = fn (UploadedFile $file, string $purpose = 'logo') => $this->actingAs($owner)->post('/api/v1/store/media', ['purpose' => $purpose, 'file' => $file], ['Accept' => 'application/json']);

        $send(UploadedFile::fake()->create('logo.svg', 2, 'image/svg+xml'))->assertStatus(422);
        $send(UploadedFile::fake()->image('logo.gif', 200, 200))->assertStatus(422);
        $send(UploadedFile::fake()->image('logo.webp', 200, 200))->assertStatus(422);
        $send(UploadedFile::fake()->create('fake.png', 10, 'image/png'))->assertStatus(422);         // not a real image
        $send(UploadedFile::fake()->image('big.png', 200, 200)->size(6000))->assertStatus(422);       // over 5 MB
        $send($this->png(10, 10), 'logo')->assertStatus(422);                                          // too small for a logo
        $send($this->png(32, 32, 'icon.png'), 'favicon')->assertCreated();
        $send($this->png(), 'wallpaper')->assertStatus(422);                                           // unknown purpose
        $this->assertSame(1, StoreMedia::query()->withoutTenantScope()->count());
    }

    public function test_staff_need_the_theme_or_seo_permission_for_the_purpose(): void
    {
        $store = Store::factory()->create();
        $role = Role::factory()->create(['store_id' => $store->id, 'slug' => 'custom-seo', 'name' => 'SEO']);
        $role->permissions()->attach(\App\Domain\Identity\Models\Permission::query()->firstOrCreate(['key' => 'seo.manage'], ['group' => 'seo', 'description' => 'Manage SEO'])->id);
        $seo = User::factory()->create();
        $store->users()->attach($seo, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($seo)->post('/api/v1/store/media', ['purpose' => 'logo', 'file' => $this->png()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($seo)->post('/api/v1/store/media', ['purpose' => 'social', 'file' => $this->png(1200, 630)], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.purpose', 'social');
    }

    public function test_a_theme_cannot_point_to_another_stores_upload_or_a_made_up_one(): void
    {
        $mine = Store::factory()->create();
        $other = Store::factory()->create();
        $owner = $this->owner($mine);
        $otherPath = $this->actingAs($this->owner($other))->post('/api/v1/store/media', ['purpose' => 'logo', 'file' => $this->png()], ['Accept' => 'application/json'])->json('data.path');
        $this->app['auth']->forgetGuards();

        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => ['branding' => ['logo_url' => $otherPath]]])
            ->assertStatus(422)->assertJsonPath('code', 'invalid_theme_config');
        $forged = '/storage/stores/'.$mine->public_id.'/media/'.strtolower((string) \Illuminate\Support\Str::ulid()).'.png';
        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => ['branding' => ['logo_url' => $forged]]])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => ['branding' => ['logo_url' => '/storage/../.env']]])->assertStatus(422);
        $this->actingAs($owner)->putJson('/api/v1/store/theme/draft', ['config' => ['branding' => ['logo_url' => 'http://insecure.example/logo.png']]])->assertStatus(422);
    }
}
