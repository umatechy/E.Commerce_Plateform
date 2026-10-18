<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UI-20 — the admin theme colour and language are kept with the account
 * and come back with the signed-in user, so they follow it to every device.
 */
final class AccountPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_saves_the_theme_and_language_and_gets_them_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['theme' => 'emerald'])->assertOk()->assertExactJson(['data' => ['theme' => 'emerald']]);
        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['language' => 'ur'])->assertOk()->assertExactJson(['data' => ['theme' => 'emerald', 'language' => 'ur']]);

        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.preferences.theme', 'emerald')->assertJsonPath('data.preferences.language', 'ur');
    }

    public function test_only_known_themes_and_languages_are_kept(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['theme' => 'neon', 'language' => 'fr'])
            ->assertStatus(422)->assertJsonValidationErrors(['theme', 'language']);
        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['theme' => 'slate', 'colour' => 'red'])->assertOk()->assertExactJson(['data' => ['theme' => 'slate']]);
        $this->assertSame(['theme' => 'slate'], $user->refresh()->admin_preferences);
    }

    public function test_a_user_saves_light_dark_or_system(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['mode' => 'dark'])->assertOk()->assertJsonPath('data.mode', 'dark');
        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['mode' => 'neon'])->assertStatus(422)->assertJsonValidationErrors(['mode']);
    }

    public function test_a_visitor_cannot_save_preferences(): void
    {
        $this->putJson('/api/v1/auth/preferences', ['theme' => 'slate'])->assertUnauthorized();
    }

    public function test_saved_list_views_are_kept_per_list_and_limited(): void
    {
        $user = User::factory()->create();
        $views = [['name' => 'COD to confirm', 'query' => 'status=pending_confirmation'], ['name' => 'Delivered', 'query' => 'status=delivered']];

        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['saved_views' => ['orders' => $views]])->assertOk()->assertJsonPath('data.saved_views.orders.1.name', 'Delivered');
        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['saved_views' => ['products' => [['name' => 'Drafts', 'query' => 'status=draft']]]])->assertOk()
            ->assertJsonPath('data.saved_views.orders.0.query', 'status=pending_confirmation')
            ->assertJsonPath('data.saved_views.products.0.name', 'Drafts');

        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['saved_views' => ['payouts' => []]])->assertStatus(422);
        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['saved_views' => ['orders' => array_fill(0, 11, ['name' => 'x', 'query' => ''])]])->assertStatus(422);
        $this->actingAs($user)->putJson('/api/v1/auth/preferences', ['saved_views' => ['orders' => [['name' => str_repeat('n', 41), 'query' => '']]]])->assertStatus(422);
    }
}
