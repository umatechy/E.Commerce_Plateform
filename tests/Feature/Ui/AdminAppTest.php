<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI-19 — the admin installs on a phone like an app: a manifest named after
 * the platform (Platform settings), linked from admin pages and sign-in, with
 * no service worker (admin data is never kept offline on the device).
 */
final class AdminAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_manifest_is_named_after_the_platform_and_starts_at_the_admin(): void
    {
        $response = $this->get('/admin.webmanifest')->assertOk();

        $this->assertStringStartsWith('application/manifest+json', (string) $response->headers->get('Content-Type'));
        $response->assertJsonPath('name', 'Umar Techy Admin')
            ->assertJsonPath('short_name', 'Umar Techy')
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('icons.0.src', '/brand/app-icon.svg');
        $this->assertFileExists(public_path('brand/app-icon.svg'));
    }

    public function test_the_sign_in_page_links_the_admin_manifest(): void
    {
        $this->withoutVite()->get('/login')->assertOk()->assertSee('<link rel="manifest" href="/admin.webmanifest">', false);
    }
}
