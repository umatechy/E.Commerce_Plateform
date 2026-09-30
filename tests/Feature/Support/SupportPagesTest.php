<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Domain\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase B26 — the support pages on the storefront and in the admin. */
final class SupportPagesTest extends TestCase
{
    use InteractsWithSupport, RefreshDatabase;

    public function test_storefront_support_pages_render_and_private_ones_are_not_indexed(): void
    {
        $store = $this->openStore();
        $base = "/shop/{$store->slug}";
        $id = '01JSUPPORTTICKET0000000001';

        $this->withoutVite()->get("{$base}/contact")->assertOk()
            ->assertInertia(fn ($p) => $p->component('Storefront/Contact')
                ->where('categories', ['order', 'shipping', 'payment', 'returns', 'product', 'account', 'other'])
                ->where('seo.canonical', fn ($url) => str_ends_with((string) $url, '/contact'))
                ->where('seo.robots', fn ($robots) => ! str_contains((string) $robots, 'noindex')));

        foreach (['/account/support' => 'Support', '/account/support/new' => 'SupportNew', "/account/support/{$id}" => 'SupportTicket'] as $path => $page) {
            $this->withoutVite()->get($base.$path)->assertOk()
                ->assertInertia(fn ($p) => $p->component("Storefront/Account/{$page}")->where('seo.robots', 'noindex, nofollow'));
        }
        $this->withoutVite()->get("{$base}/account/support/{$id}")->assertInertia(fn ($p) => $p->where('ticket_id', $id));
        $this->withoutVite()->get("{$base}/account/support/not-a-ticket-id")->assertNotFound();
    }

    public function test_a_guests_private_link_page_is_a_shell_that_is_never_indexed(): void
    {
        $store = $this->openStore();

        // The token is in the link's #fragment, so the page request never carries it.
        $this->withoutVite()->get("/shop/{$store->slug}/support/tickets/01JSUPPORTTICKET0000000001")
            ->assertOk()->assertInertia(fn ($p) => $p->component('Storefront/SupportTicket')
                ->where('ticket_id', '01JSUPPORTTICKET0000000001')->missing('token')->where('seo.robots', 'noindex, nofollow'));
    }

    public function test_admin_support_pages_need_a_session_and_the_platform_inbox_needs_platform_staff(): void
    {
        $store = $this->openStore();
        $owner = $this->owner($store);

        // Signed out: to the sign-in page, which sends them back to the ticket afterwards.
        $this->withoutVite()->get('/support?ticket=01JSUPPORTTICKET0000000001')->assertRedirect('/login');
        $this->withoutVite()->get('/login')->assertOk()->assertInertia(fn ($p) => $p->component('Auth/Login')->where('intended', '/support?ticket=01JSUPPORTTICKET0000000001'));
        $this->withSession(['url.intended' => 'https://evil.example/support'])->withoutVite()->get('/login')->assertInertia(fn ($p) => $p->where('intended', null));
        $this->withoutVite()->actingAs($owner)->get('/support')->assertOk()->assertInertia(fn ($p) => $p->component('Support/Index'));
        $this->withoutVite()->actingAs($owner)->get('/support/platform')->assertOk()->assertInertia(fn ($p) => $p->component('Support/Platform'));
        $this->withoutVite()->actingAs($owner)->get('/super-admin/support')->assertForbidden();

        $platformAgent = User::factory()->create(['platform_role' => 'support_agent']);
        $this->withoutVite()->actingAs($platformAgent)->get('/super-admin/support')->assertOk()->assertInertia(fn ($p) => $p->component('SuperAdmin/Support'));
    }
}
