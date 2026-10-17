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

    public function test_a_visitor_cannot_save_preferences(): void
    {
        $this->putJson('/api/v1/auth/preferences', ['theme' => 'slate'])->assertUnauthorized();
    }
}
