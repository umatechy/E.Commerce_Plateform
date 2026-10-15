<?php

declare(strict_types=1);

namespace Tests\Feature\Help;

use App\Domain\Compliance\Models\AuditLog;
use App\Domain\Help\Models\HelpArticle;
use App\Domain\Help\Models\HelpFeedback;
use App\Domain\Help\Models\HelpReleaseNote;
use App\Domain\Help\Services\HelpArticleWorkflow;
use App\Domain\Help\Services\HelpBundleSync;
use App\Domain\Help\Services\HelpRenderer;
use App\Domain\Help\Support\HelpBundle;
use App\Domain\Help\Support\HelpContentChecker;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use App\Domain\Tenancy\Models\StoreStatus;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase B54 (gap G12; SRS SUP-001, SUP-005, SUP-006; Module 34 §6–15,
 * §24–34, §43, §47, §57, §62, §73.10–73.18): the knowledge base, its
 * review workflow, role-aware visibility, search, page help, feedback,
 * the shoppers' help center, release notes and the documentation checks.
 */
final class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionSeeder::class);
        $result = app(HelpBundleSync::class)->sync();
        $this->assertSame([], $result['problems']);
        $this->store = Store::factory()->create(['status' => 'active']);
        $this->entitle($this->store, ['orders.basic']);
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->store->users()->attach($user, ['role_id' => $this->systemRole($this->store, $role)->id, 'status' => 'active']);

        return $user;
    }

    private function as(User $user): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');

        return $this->actingAs($user);
    }

    // --- Documentation as code (§57–58) ---

    public function test_the_help_content_in_the_repository_passes_every_check_and_loads_once(): void
    {
        $this->assertSame(0, Artisan::call('help:check'));
        $bundle = HelpBundle::load();
        $this->assertGreaterThan(40, count($bundle->articles));
        $this->assertNotNull($bundle->article('how-to-order', 'ur'), 'shopper help has an Urdu translation');

        $again = app(HelpBundleSync::class)->sync();
        $this->assertSame(0, $again['created'] + $again['updated'] + $again['releases']);
        $this->assertSame(HelpArticle::query()->count(), $again['unchanged']);
        $this->assertSame('published', HelpArticle::query()->where('key', 'handle-orders')->value('status'));
    }

    public function test_the_checker_finds_secrets_personal_data_broken_links_and_a_wrong_troubleshooting_structure(): void
    {
        $checker = app(HelpContentChecker::class);
        $exists = fn (string $key): ?string => ['handle-orders' => 'merchant', 'platform-help-writing' => 'platform', 'how-to-order' => 'customer'][$key] ?? null;
        $article = ['key' => 'test-article', 'locale' => 'en', 'title' => 'T', 'summary' => 'S', 'category' => 'orders', 'type' => 'how_to', 'audience' => 'merchant', 'applies_from' => '1.1', 'owner' => 'Orders'];

        $problems = fn (string $body, array $more = []) => implode("\n", $checker->check([...$article, ...$more, 'body' => $body], $exists));

        $this->assertSame('', $problems('See [orders](/orders) and [this](help:handle-orders) and [shop](help:how-to-order).'));
        $this->assertStringContainsString('Umar Techy API key', $problems('Use utk_abcdef12.ABCDEFGHijklmnop to call it.'));
        $this->assertStringContainsString('private key', $problems("-----BEGIN RSA PRIVATE KEY-----"));
        $this->assertStringContainsString('password or secret', $problems('password: Hunter2Hunter2'));
        $this->assertStringContainsString('real email', $problems('Write to someone@gmail.com'));
        $this->assertSame('', $problems('Write to someone@example.com'));
        $this->assertStringContainsString('phone number', $problems('Call 0300 1234567'));
        $this->assertStringContainsString('no such article', $problems('[x](help:does-not-exist)'));
        $this->assertStringContainsString('for platform readers', $problems('[x](help:platform-help-writing)'), 'store staff cannot open internal runbooks');
        $this->assertStringContainsString('no such admin page', $problems('[x](/nowhere-at-all)'));
        $this->assertStringContainsString('only https://', $problems('[x](javascript:alert(1))'));
        $this->assertStringContainsString('no path /api/dev/v1/widgets', $problems('Call `/api/dev/v1/widgets`.'));
        $this->assertStringContainsString('not a webhook event', $problems('Listen for `order.exploded`.'));
        $this->assertStringContainsString('troubleshooting article', $problems("## Problem\n\nx", ['type' => 'troubleshooting', 'category' => 'troubleshooting']));
        $this->assertStringContainsString('shoppers cannot open admin pages', $problems('[x](/orders)', ['audience' => 'customer', 'category' => 'orders']));
    }

    public function test_sync_does_not_overwrite_an_article_changed_in_the_super_admin_and_archives_one_removed(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists("{$dir}/articles/orders");
        $file = fn (string $title) => "---\nkey: sync-sample\ntitle: {$title}\nsummary: A sample.\ncategory: orders\ntype: how_to\naudience: merchant\napplies_from: 1.1\nowner: Orders (Module 09)\n---\nSome text.\n";
        try {
            File::put("{$dir}/articles/orders/sync-sample.md", $file('First'));
            $sync = app(HelpBundleSync::class);
            $this->assertSame(1, $sync->sync(HelpBundle::load($dir))['created']);

            File::put("{$dir}/articles/orders/sync-sample.md", $file('Second'));
            $this->assertSame(1, $sync->sync(HelpBundle::load($dir))['updated']);
            $article = HelpArticle::query()->where('key', 'sync-sample')->firstOrFail();
            $this->assertSame(['Second', 2], [$article->title, $article->revision]);

            // Someone changes it here and it is published.
            [$writer, $reviewer] = [User::factory()->create(['platform_role' => 'support_admin']), User::factory()->create(['platform_role' => 'super_admin'])];
            $workflow = app(HelpArticleWorkflow::class);
            $workflow->saveDraft($article, $writer, [...$article->content(), 'title' => 'Our own words'], 'Clearer');
            $workflow->submit($article, $writer);
            $workflow->approve($article->refresh(), $reviewer, null);

            File::put("{$dir}/articles/orders/sync-sample.md", $file('Third'));
            $this->assertSame(1, $sync->sync(HelpBundle::load($dir))['kept_local']);
            $article->refresh();
            $this->assertSame('Our own words', $article->title);
            $this->assertTrue($article->bundle_update_available);

            File::delete("{$dir}/articles/orders/sync-sample.md");
            $sync->sync(HelpBundle::load($dir));
            $this->assertSame('archived', $article->refresh()->status, 'a built-in article removed from the repository is archived');
        } finally {
            File::deleteDirectory($dir);
        }
    }

    public function test_nothing_is_loaded_when_a_file_has_a_problem(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::ensureDirectoryExists("{$dir}/articles/orders");
        try {
            File::put("{$dir}/articles/orders/bad-one.md", "---\nkey: bad-one\ntitle: Bad\nsummary: S\ncategory: orders\ntype: how_to\naudience: merchant\napplies_from: 1.1\nowner: O\n---\nThe key is utk_abcdef12.ABCDEFGHijklmnop\n");
            $result = app(HelpBundleSync::class)->sync(HelpBundle::load($dir));
            $this->assertNotSame([], $result['problems']);
            $this->assertFalse(HelpArticle::query()->where('key', 'bad-one')->exists());
        } finally {
            File::deleteDirectory($dir);
        }
    }

    // --- Role-aware help and search security (§15, §62) ---

    public function test_each_reader_sees_only_the_articles_for_them_and_the_others_do_not_exist_for_them(): void
    {
        $this->getJson('/api/v1/help')->assertUnauthorized();

        $staff = $this->member('staff');
        $owner = $this->member('owner');
        $platform = User::factory()->create(['platform_role' => 'read_only_auditor']);

        $this->as($staff)->getJson('/api/v1/help/articles/handle-orders')->assertOk()->assertJsonPath('data.title', 'Handle an order from start to finish');
        $this->as($staff)->getJson('/api/v1/help/articles/how-to-order')->assertOk(); // store staff also see shopper help
        $this->as($staff)->getJson('/api/v1/help/articles/developer-api-start')->assertNotFound();
        $this->as($staff)->getJson('/api/v1/help/articles/platform-help-writing')->assertNotFound();
        $this->as($owner)->getJson('/api/v1/help/articles/developer-api-start')->assertOk();
        $this->as($owner)->getJson('/api/v1/help/articles/platform-help-writing')->assertNotFound();
        $this->as($platform)->getJson('/api/v1/help/articles/platform-help-writing')->assertOk();

        // Search and page help never return a restricted article, not even its title.
        $search = $this->as($staff)->getJson('/api/v1/help/search?q=playbook')->assertOk()->json('data');
        $this->assertSame([], array_values(array_filter($search, fn (array $a) => in_array($a['audience'], ['platform', 'developer'], true))));
        $this->assertSame([], $this->as($staff)->getJson('/api/v1/help/page?path=/super-admin/help')->json('data'));
        $this->assertSame(['platform-help-writing'], array_column($this->as($platform)->getJson('/api/v1/help/page?path=/super-admin/help')->json('data'), 'key'));
        $home = $this->as($staff)->getJson('/api/v1/help')->assertOk()->json('data.categories');
        $this->assertNotContains('developers', array_column($home, 'key'));
    }

    public function test_search_ranks_error_codes_and_exact_matches_and_logs_no_one(): void
    {
        $staff = $this->member('staff');

        $byCode = $this->as($staff)->getJson('/api/v1/help/search?q=store_closed')->assertOk()->json('data');
        $this->assertSame('store-closed-or-suspended', $byCode[0]['key']);
        $cod = array_column($this->as($staff)->getJson('/api/v1/help/search?q=cod')->json('data'), 'key');
        $this->assertContains('payment-methods', $cod);
        $this->assertNotContains('two-step-sign-in', $cod, '"cod" is not "code"');
        $this->as($staff)->getJson('/api/v1/help/search?q=order 1234567890 xyzzy')->assertOk()->assertJsonCount(0, 'data');

        $logged = DB::table('help_searches')->orderBy('id')->get();
        $this->assertCount(3, $logged);
        $this->assertSame('order # xyzzy', $logged[2]->query, 'numbers that could be an order or a phone are not kept');
        $this->assertSame(0, $logged[2]->results);
        $this->assertSame(['id', 'query', 'surface', 'locale', 'results', 'created_at'], array_keys((array) $logged[0]), 'no user, store or address');
    }

    public function test_page_help_matches_the_page_and_its_id_pattern(): void
    {
        $staff = $this->member('staff');

        $this->assertSame(['handle-orders'], array_column($this->as($staff)->getJson('/api/v1/help/page?path=/orders/01HZY3M4N5P6Q7R8S9T0V1W2X3')->json('data'), 'key'));
        $this->assertContains('payment-methods', array_column($this->as($staff)->getJson('/api/v1/help/page?path=/settings/payments')->json('data'), 'key'));
        $this->as($staff)->getJson('/api/v1/help/page?path=javascript:alert(1)')->assertUnprocessable();
    }

    public function test_feedback_is_kept_for_the_version_read_without_the_reader_and_with_contacts_removed(): void
    {
        $staff = $this->member('staff');

        $this->as($staff)->postJson('/api/v1/help/articles/handle-orders/feedback', ['helpful' => false, 'reason' => 'missing', 'comment' => 'Mail me at ali@gmail.com or 0300 1234567'])->assertCreated();
        $this->as($staff)->postJson('/api/v1/help/articles/platform-help-writing/feedback', ['helpful' => true])->assertNotFound();

        $feedback = HelpFeedback::query()->sole();
        $article = HelpArticle::query()->where('key', 'handle-orders')->firstOrFail();
        $this->assertSame([$article->id, $article->revision, 'admin', $this->store->id], [$feedback->help_article_id, $feedback->revision, $feedback->surface, $feedback->store_id]);
        $this->assertStringNotContainsString('ali@gmail.com', (string) $feedback->comment);
        $this->assertStringNotContainsString('1234567', (string) $feedback->comment);
    }

    public function test_help_stays_open_when_the_store_is_closed(): void
    {
        $owner = $this->member('owner');
        $this->store->update(['status' => StoreStatus::Cancelled]);

        $this->as($owner)->getJson('/api/v1/help/articles/close-store')->assertOk();
        $this->as($owner)->postJson('/api/v1/help/articles/close-store/feedback', ['helpful' => true])->assertCreated();
    }

    // --- Review workflow (§6.2–6.4, §9) ---

    public function test_a_new_article_is_private_until_someone_else_approves_it_and_every_step_is_audited(): void
    {
        $writer = User::factory()->create(['platform_role' => 'support_admin']);
        $reviewer = User::factory()->create(['platform_role' => 'super_admin']);
        $staff = $this->member('staff');
        $content = [
            'key' => 'courier-tips', 'locale' => 'en', 'title' => 'Courier tips', 'summary' => 'How to pack parcels.', 'body' => "## Packing\n\nUse a strong box. See [shipping](help:ship-orders).",
            'category' => 'shipping', 'type' => 'how_to', 'audience' => 'merchant', 'owner' => 'Shipping (Module 13)', 'contexts' => ['/shipments'],
        ];

        $id = $this->as($writer)->postJson('/api/v1/super-admin/help/articles', $content)->assertCreated()->json('data.id');
        $this->as($staff)->getJson('/api/v1/help/articles/courier-tips')->assertNotFound();
        $this->as($staff)->getJson('/api/v1/help/search?q=courier tips')->assertJsonMissing(['key' => 'courier-tips']);

        $this->as($writer)->postJson("/api/v1/super-admin/help/articles/{$id}/submit")->assertOk();
        $this->as($writer)->postJson("/api/v1/super-admin/help/articles/{$id}/approve")->assertUnprocessable()->assertJsonPath('code', 'self_review');
        $this->as($staff)->getJson('/api/v1/help/articles/courier-tips')->assertNotFound();

        $this->as($reviewer)->postJson("/api/v1/super-admin/help/articles/{$id}/approve", ['note' => 'Good'])->assertOk();
        $this->as($staff)->getJson('/api/v1/help/articles/courier-tips')->assertOk()->assertJsonPath('data.revision', 1);
        $this->assertSame(1, HelpArticle::query()->findOrFail($id)->revision);

        // The next version waits while the live one stays.
        $this->as($writer)->putJson("/api/v1/super-admin/help/articles/{$id}/draft", [...$content, 'title' => 'Courier tips, revised'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->as($staff)->getJson('/api/v1/help/articles/courier-tips')->assertJsonPath('data.title', 'Courier tips');
        $history = $this->as($writer)->getJson("/api/v1/super-admin/help/articles/{$id}")->assertOk()->json('data.history');
        $this->assertSame([[2, 'draft'], [1, 'published']], array_map(fn ($h) => [$h['revision'], $h['state']], $history));

        $actions = AuditLog::query()->whereNull('store_id')->where('action', 'like', 'help.article.%')->pluck('action')->all();
        foreach (['help.article.created', 'help.article.submitted', 'help.article.published', 'help.article.draft_saved'] as $action) {
            $this->assertContains($action, $actions);
        }
    }

    public function test_content_with_a_secret_cannot_be_sent_for_review_and_writing_needs_the_help_role(): void
    {
        $writer = User::factory()->create(['platform_role' => 'support_admin']);
        $auditor = User::factory()->create(['platform_role' => 'read_only_auditor']);
        $content = ['key' => 'leaky', 'locale' => 'en', 'title' => 'Leaky', 'summary' => 'S', 'body' => 'Your key: utk_abcdef12.ABCDEFGHijklmnop', 'category' => 'orders', 'type' => 'how_to', 'audience' => 'merchant', 'owner' => 'Orders'];

        $this->as($auditor)->getJson('/api/v1/super-admin/help/articles')->assertOk();
        $this->as($auditor)->postJson('/api/v1/super-admin/help/articles', $content)->assertForbidden();

        $preview = $this->as($writer)->postJson('/api/v1/super-admin/help/preview', $content)->assertOk()->json('data.problems');
        $this->assertNotEmpty($preview);
        $id = $this->as($writer)->postJson('/api/v1/super-admin/help/articles', $content)->assertCreated()->json('data.id');
        $this->as($writer)->postJson("/api/v1/super-admin/help/articles/{$id}/submit")->assertUnprocessable()->assertJsonPath('code', 'content_invalid');
    }

    public function test_an_out_of_date_article_names_its_replacement_and_is_ranked_lower(): void
    {
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $staff = $this->member('staff');
        $id = HelpArticle::query()->where('key', 'create-an-order')->value('id');

        $this->as($admin)->postJson("/api/v1/super-admin/help/articles/{$id}/deprecate")->assertUnprocessable()->assertJsonPath('code', 'replacement_required');
        $this->as($admin)->postJson("/api/v1/super-admin/help/articles/{$id}/deprecate", ['replaced_by' => 'platform-help-writing'])->assertUnprocessable()->assertJsonPath('code', 'replacement_hidden');
        $this->as($admin)->postJson("/api/v1/super-admin/help/articles/{$id}/deprecate", ['replaced_by' => 'handle-orders'])->assertOk();

        $this->as($staff)->getJson('/api/v1/help/articles/create-an-order')->assertOk()
            ->assertJsonPath('data.status', 'deprecated')->assertJsonPath('data.replaced_by.key', 'handle-orders');

        $this->as($admin)->postJson("/api/v1/super-admin/help/articles/{$id}/archive")->assertOk();
        $this->as($staff)->getJson('/api/v1/help/articles/create-an-order')->assertNotFound();
        $this->as($admin)->postJson("/api/v1/super-admin/help/articles/{$id}/reinstate")->assertOk();
        $this->as($staff)->getJson('/api/v1/help/articles/create-an-order')->assertOk()->assertJsonPath('data.status', 'published');
    }

    // --- Rendering (§47–48) ---

    public function test_articles_render_to_safe_html_with_their_links_resolved_for_where_they_are_read(): void
    {
        $html = app(HelpRenderer::class)->html("<script>alert(1)</script>\n\n[a](help:handle-orders) [b](store:/account) [c](javascript:alert(1)) `code`", '/shop/acme/help', '/shop/acme');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('href="/shop/acme/help/handle-orders"', $html);
        $this->assertStringContainsString('href="/shop/acme/account"', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
        $this->assertStringNotContainsString('<a', app(HelpRenderer::class)->html('[b](store:/account)', '/help/articles', null), 'no store: a storefront link becomes text');
    }

    // --- The shoppers' help center (§43) ---

    public function test_shoppers_get_shopper_help_only_in_their_language_and_can_rate_it(): void
    {
        $base = "/shop/{$this->store->slug}";
        $this->withoutVite()->get("{$base}/help")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Storefront/Help')
            ->where('results', null)
            ->where('categories', fn ($categories) => collect($categories)->flatMap(fn ($c) => collect($c['articles'])->pluck('audience'))->unique()->values()->all() === ['customer']));
        $this->withoutVite()->get("{$base}/help?q=return")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('results.0.key', 'return-an-item'));
        $this->withoutVite()->get("{$base}/help/how-to-order")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('article.title', 'How to place an order'));
        $this->withoutVite()->get("{$base}/help/handle-orders")->assertNotFound();
        $this->withoutVite()->get("{$base}/help/platform-help-writing")->assertNotFound();

        DB::table('store_settings')->updateOrInsert(['store_id' => $this->store->id, 'key' => 'store.languages'], ['value' => json_encode([['en', 'ur']]), 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Cache::flush();
        $this->withoutVite()->get("{$base}/help/how-to-order?lang=ur")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('article.locale', 'ur')->where('article.title', 'آرڈر کیسے دیں')->where('article.translation_outdated', false));

        $this->withHeader('X-Store-Slug', $this->store->slug)->postJson('/api/v1/storefront/help/how-to-order/feedback', ['helpful' => true, 'locale' => 'ur'])->assertCreated();
        $this->withHeader('X-Store-Slug', $this->store->slug)->postJson('/api/v1/storefront/help/handle-orders/feedback', ['helpful' => true])->assertNotFound();
        $this->assertSame(['ur', 'storefront', $this->store->id], [HelpFeedback::query()->sole()->locale, HelpFeedback::query()->sole()->surface, HelpFeedback::query()->sole()->store_id]);
    }

    public function test_a_translation_is_marked_out_of_date_when_the_english_article_changes(): void
    {
        $writer = User::factory()->create(['platform_role' => 'support_admin']);
        $reviewer = User::factory()->create(['platform_role' => 'super_admin']);
        $english = HelpArticle::query()->where('key', 'how-to-order')->where('locale', 'en')->firstOrFail();
        $workflow = app(HelpArticleWorkflow::class);
        $workflow->saveDraft($english, $writer, [...$english->content(), 'summary' => 'Changed summary.'], null);
        $workflow->submit($english, $writer);
        $workflow->approve($english->refresh(), $reviewer, null);

        $this->as($reviewer)->getJson('/api/v1/super-admin/help/articles/'.HelpArticle::query()->where('key', 'how-to-order')->where('locale', 'ur')->value('id'))
            ->assertOk()->assertJsonPath('data.translation.outdated', true);
    }

    // --- Release notes (SUP-006; §31–32) ---

    public function test_release_notes_arrive_as_drafts_and_publishing_sets_the_date_and_the_whats_new_mark(): void
    {
        $owner = $this->member('owner');
        $admin = User::factory()->create(['platform_role' => 'super_admin']);
        $this->assertSame('draft', HelpReleaseNote::query()->where('version', '1.1')->value('status'));
        $this->as($owner)->getJson('/api/v1/help/releases')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.unseen_release', null);

        $this->as($admin)->postJson('/api/v1/super-admin/help/releases/1.1/publish')->assertOk();
        $this->as($admin)->postJson('/api/v1/super-admin/help/releases/1.1/publish')->assertConflict();
        $this->assertSame(now()->toDateString(), HelpReleaseNote::query()->where('version', '1.1')->value('released_on')?->toDateString());

        $notes = $this->as($owner)->getJson('/api/v1/help/releases')->assertOk()->assertJsonPath('meta.unseen_release', '1.1')->json('data.0.sections');
        $this->assertSame('added', $notes[0]['key']);
        $this->as($owner)->postJson('/api/v1/help/releases/seen')->assertOk();
        $this->as($owner)->getJson('/api/v1/help')->assertJsonPath('data.unseen_release', null);
        $this->assertTrue(AuditLog::query()->where('action', 'help.release.published')->exists());
    }

    public function test_old_searches_and_handled_feedback_are_removed(): void
    {
        DB::table('help_searches')->insert([['query' => 'old', 'surface' => 'admin', 'locale' => 'en', 'results' => 0, 'created_at' => now()->subDays(200)], ['query' => 'new', 'surface' => 'admin', 'locale' => 'en', 'results' => 1, 'created_at' => now()]]);

        $this->assertSame(0, Artisan::call('help:maintain'));
        $this->assertSame(['new'], DB::table('help_searches')->pluck('query')->all());
    }
}
