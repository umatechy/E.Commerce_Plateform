<?php

declare(strict_types=1);

namespace Tests\Feature\Returns;

use App\Domain\Identity\Models\User;
use App\Domain\Notifications\Models\NotificationMessage;
use App\Domain\Orders\Models\Customer;
use App\Domain\Returns\Models\ReturnPhoto;
use App\Domain\Returns\Models\ReturnRequest;
use App\Domain\Returns\Services\ReturnService;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B34 — what B33 left out of Module 09 §45: photos on a return
 * request (private files) and returns by guests (through a link sent to
 * the order's email).
 */
final class ReturnPhotosAndGuestTest extends TestCase
{
    use BuildsReturns, RefreshDatabase;

    private function photo(string $name = 'leak.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 300);
    }

    public function test_staff_attach_private_photos_that_only_the_api_serves(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $order = $this->deliveredOrder(2);
        $id = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->json('data.id');

        $this->actingAs($this->owner)->post("/api/v1/returns/{$id}/photos", ['photo' => UploadedFile::fake()->create('note.pdf', 20, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        // A file that only claims to be an image is refused by the decoder.
        $this->actingAs($this->owner)->post("/api/v1/returns/{$id}/photos", ['photo' => UploadedFile::fake()->createWithContent('fake.jpg', '<html><script>alert(1)</script></html>')], ['Accept' => 'application/json'])->assertUnprocessable();

        $response = $this->actingAs($this->owner)->post("/api/v1/returns/{$id}/photos", ['photo' => $this->photo()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonCount(1, 'data.photos')->assertJsonPath('data.photos.0.uploaded_by', 'staff');
        $photoId = $response->json('data.photos.0.id');
        $photo = ReturnPhoto::query()->sole();

        // On the private disk, under the store and the return; nothing on the public one.
        Storage::disk('local')->assertExists($photo->path);
        $this->assertStringStartsWith("stores/{$this->store->id}/returns/{$id}/", $photo->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        // The response of the return carries no file path or URL.
        $this->assertStringNotContainsString($photo->path, (string) $response->getContent());

        $image = $this->actingAs($this->owner)->get("/api/v1/returns/{$id}/photos/{$photoId}")->assertOk();
        $this->assertSame('image/jpeg', $image->headers->get('Content-Type'));
        $this->assertSame('nosniff', $image->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('private', (string) $image->headers->get('Cache-Control'));
        $this->assertNotFalse(@imagecreatefromstring((string) $image->getContent()), 'the served bytes are a real image');

        // Another return's address does not open this photo; nor does another store.
        $other = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->json('data.id');
        $this->actingAs($this->owner)->get("/api/v1/returns/{$other}/photos/{$photoId}")->assertNotFound();
        $outsider = User::factory()->create();
        $elsewhere = Store::factory()->create();
        $elsewhere->users()->attach($outsider, ['role_id' => $this->systemRole($elsewhere, 'owner')->id, 'status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($outsider)->get("/api/v1/returns/{$id}/photos/{$photoId}")->assertNotFound();

        // A viewer sees photos but cannot add or remove them.
        $viewer = $this->staffWith(['returns.view']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($viewer)->get("/api/v1/returns/{$id}/photos/{$photoId}")->assertOk();
        $this->actingAs($viewer)->post("/api/v1/returns/{$id}/photos", ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($viewer)->deleteJson("/api/v1/returns/{$id}/photos/{$photoId}")->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner)->deleteJson("/api/v1/returns/{$id}/photos/{$photoId}")->assertOk()->assertJsonCount(0, 'data.photos');
        Storage::disk('local')->assertMissing($photo->path);
    }

    public function test_a_return_takes_a_limited_number_of_photos_and_none_once_closed(): void
    {
        Storage::fake('local');
        config(['returns.photos.max_per_return' => 2]);
        $order = $this->deliveredOrder(1);
        $id = $this->actingAs($this->owner)->postJson("/api/v1/orders/{$order->public_id}/returns", $this->body($order, 1))->json('data.id');

        foreach ([1, 2] as $n) {
            $this->actingAs($this->owner)->post("/api/v1/returns/{$id}/photos", ['photo' => $this->photo("p{$n}.png")], ['Accept' => 'application/json'])->assertCreated();
        }
        $this->actingAs($this->owner)->post("/api/v1/returns/{$id}/photos", ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');

        $this->actingAs($this->owner)->postJson("/api/v1/returns/{$id}/reject", ['note' => 'Used'])->assertOk();
        ReturnPhoto::query()->first()->delete();
        $this->actingAs($this->owner)->post("/api/v1/returns/{$id}/photos", ['photo' => $this->photo()], ['Accept' => 'application/json'])->assertStatus(409)->assertJsonPath('code', 'photos_closed');
    }

    public function test_a_customer_adds_photos_to_their_own_request_until_the_store_answers(): void
    {
        Storage::fake('local');
        $customer = Customer::factory()->for($this->store)->create(['password' => Hash::make('correct-horse-99')]);
        $stranger = Customer::factory()->for($this->store)->create(['password' => Hash::make('correct-horse-99')]);
        $order = $this->deliveredOrder(2, $customer);
        $this->storeSetting('returns.customer_requests_enabled', true);
        $headers = fn (Customer $c) => ['Authorization' => 'Bearer '.$c->createToken('t')->plainTextToken, 'X-Store-Slug' => $this->store->slug, 'Accept' => 'application/json'];
        $returns = app(ReturnService::class);
        $body = $this->body($order, 1);
        $return = $returns->request($order, $body['items'], $body, $customer, 'c-1');
        $id = $return->public_id;

        $this->app['auth']->forgetGuards();
        $photoId = $this->post("/api/v1/customer/returns/{$id}/photos", ['photo' => $this->photo()], $headers($customer))
            ->assertCreated()->assertJsonPath('data.photos.0.uploaded_by', 'customer')->assertJsonPath('data.can.add_photos', true)->json('data.photos.0.id');
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/customer/returns/{$id}/photos/{$photoId}", $headers($customer))->assertOk();

        // Someone else's return, and someone else's photo, are not found.
        $this->app['auth']->forgetGuards();
        $this->post("/api/v1/customer/returns/{$id}/photos", ['photo' => $this->photo()], $headers($stranger))->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/customer/returns/{$id}/photos/{$photoId}", $headers($stranger))->assertNotFound();

        // After the store answered, the request is what it was.
        $returns->approve($return->fresh(), [], $this->owner);
        $this->app['auth']->forgetGuards();
        $this->post("/api/v1/customer/returns/{$id}/photos", ['photo' => $this->photo()], $headers($customer))->assertStatus(409)->assertJsonPath('code', 'photos_closed');
        $this->assertSame(1, ReturnPhoto::query()->count());
    }

    public function test_erasing_a_customer_removes_the_files_of_their_photos(): void
    {
        Storage::fake('local');
        $customer = Customer::factory()->for($this->store)->create(['email' => 'gone@example.com', 'password' => Hash::make('correct-horse-99')]);
        $order = $this->deliveredOrder(1, $customer);
        $body = $this->body($order, 1);
        $return = app(ReturnService::class)->request($order, $body['items'], $body, $this->owner, 's-1');
        $photo = app(\App\Domain\Returns\Services\ReturnPhotoService::class)->add($return, $this->photo(), 'staff', $this->owner->id);
        Storage::disk('local')->assertExists($photo->path);
        // Erasure waits for open orders to end; this one is finished.
        DB::table('orders')->where('id', $order->id)->update(['status' => 'completed']);

        $this->actingAs($this->owner)->postJson("/api/v1/customers/{$customer->public_id}/erase", ['reason' => 'Request', 'confirm_email' => 'gone@example.com'])->assertOk();

        Storage::disk('local')->assertMissing($photo->path);
        $this->assertSame(0, ReturnPhoto::query()->count());
    }

    public function test_a_guest_reaches_their_orders_returns_only_through_the_emailed_link(): void
    {
        Queue::fake();
        Storage::fake('local');
        $order = $this->deliveredOrder(2); // a guest order; its email is in guest_email
        $this->storeSetting('returns.customer_requests_enabled', true);
        $shop = ['X-Store-Slug' => $this->store->slug, 'Accept' => 'application/json'];
        $this->app['auth']->forgetGuards();
        auth()->guard('web')->logout();

        // The same answer whether or not anything matched; a link only for the right pair.
        $wrong = $this->postJson('/api/v1/storefront/returns/lookup', ['order_number' => $order->order_number, 'email' => 'someone@else.com'], $shop)->assertStatus(202);
        $this->assertSame(0, DB::table('return_guest_links')->count());
        $right = $this->postJson('/api/v1/storefront/returns/lookup', ['order_number' => $order->order_number, 'email' => strtoupper((string) $order->guest_email)], $shop)->assertStatus(202);
        $this->assertSame($wrong->json('message'), $right->json('message'));
        $this->assertSame(1, DB::table('return_guest_links')->count());
        // Asking again at once sends no second link.
        $this->postJson('/api/v1/storefront/returns/lookup', ['order_number' => $order->order_number, 'email' => $order->guest_email], $shop)->assertStatus(202);
        $this->assertSame(1, DB::table('return_guest_links')->count());

        // The email went to the order's address; the stored copy does not show the link.
        $message = NotificationMessage::query()->where('source_event_type', 'return.guest_link_requested')->sole();
        $this->assertSame($order->guest_email, $message->destination);
        $this->assertStringContainsString('[hidden]', $message->body);
        $this->assertStringNotContainsString('token=', $message->body);

        // The emailed token is random; only its hash is stored. Put a known one in its place.
        $token = str_repeat('k', 64);
        DB::table('return_guest_links')->update(['token_hash' => hash('sha256', $token)]);
        $link = [...$shop, 'X-Return-Token' => $token];

        $this->getJson('/api/v1/storefront/returns/guest', $shop)->assertNotFound()->assertJsonPath('code', 'return_link_invalid');
        $this->getJson('/api/v1/storefront/returns/guest', [...$shop, 'X-Return-Token' => str_repeat('x', 64)])->assertNotFound();
        $this->getJson('/api/v1/storefront/returns/guest', $link)->assertOk()
            ->assertJsonPath('data.order.order_number', $order->order_number)->assertJsonPath('data.lines.0.returnable', 2)->assertJsonPath('data.enabled', true);

        $body = $this->body($order, 1);
        $id = $this->postJson('/api/v1/storefront/returns/guest', $body, $link)->assertCreated()->assertJsonPath('data.requested_by', 'guest')->json('data.id');
        // Sent twice, one return.
        $this->assertSame($id, $this->postJson('/api/v1/storefront/returns/guest', $body, $link)->json('data.id'));
        $this->assertSame(1, ReturnRequest::query()->count());

        $this->post("/api/v1/storefront/returns/guest/{$id}/photos", ['photo' => $this->photo()], $link)->assertCreated()->assertJsonPath('data.photos.0.uploaded_by', 'guest');
        $photoId = ReturnPhoto::query()->sole()->public_id;
        $this->get("/api/v1/storefront/returns/guest/{$id}/photos/{$photoId}", $link)->assertOk();
        $this->get("/api/v1/storefront/returns/guest/{$id}/photos/{$photoId}", $shop)->assertNotFound();

        // The link opens its own order only: a return of another order is not found.
        $otherOrder = $this->deliveredOrder(1);
        $otherBody = $this->body($otherOrder, 1);
        $otherReturn = app(ReturnService::class)->request($otherOrder, $otherBody['items'], $otherBody, $this->owner, 'o-1');
        $this->app['auth']->forgetGuards();
        auth()->guard('web')->logout();
        $this->postJson("/api/v1/storefront/returns/guest/{$otherReturn->public_id}/cancel", [], $link)->assertNotFound();
        $this->postJson('/api/v1/storefront/returns/guest', [...$this->body($order, 1), 'items' => [['order_item_id' => $otherOrder->items()->value('id'), 'quantity' => 1]]], $link)->assertUnprocessable();

        // In another store the token is nothing.
        $elsewhere = Store::factory()->create(['status' => 'active']);
        $this->entitle($elsewhere, ['orders.basic']);
        $this->getJson('/api/v1/storefront/returns/guest', ['X-Store-Slug' => $elsewhere->slug, 'Accept' => 'application/json', 'X-Return-Token' => $token])->assertNotFound();

        $this->postJson("/api/v1/storefront/returns/guest/{$id}/cancel", [], $link)->assertOk()->assertJsonPath('data.status', 'cancelled');

        // An expired link is not valid any more.
        DB::table('return_guest_links')->update(['expires_at' => now()->subMinute()]);
        $this->getJson('/api/v1/storefront/returns/guest', $link)->assertNotFound()->assertJsonPath('code', 'return_link_invalid');
    }

    public function test_the_storefront_returns_page_is_never_indexed_and_passes_only_a_well_formed_token(): void
    {
        $base = "/shop/{$this->store->slug}/returns";
        $token = str_repeat('k', 64);
        $this->app['auth']->forgetGuards();
        auth()->guard('web')->logout();

        $this->withoutVite()->get($base)->assertOk()->assertInertia(fn ($p) => $p->component('Storefront/Returns')->where('token', '')->where('seo.robots', 'noindex, nofollow'));
        $this->withoutVite()->get("{$base}?token={$token}")->assertOk()->assertInertia(fn ($p) => $p->where('token', $token));
        $this->withoutVite()->get("{$base}?token=".urlencode('<script>alert(1)</script>'))->assertOk()->assertInertia(fn ($p) => $p->where('token', ''));
    }

    public function test_no_link_is_sent_for_an_order_of_a_customer_with_an_account(): void
    {
        Queue::fake();
        $customer = Customer::factory()->for($this->store)->create(['email' => 'member@example.com', 'password' => Hash::make('correct-horse-99')]);
        $order = $this->deliveredOrder(1, $customer);
        $this->app['auth']->forgetGuards();
        auth()->guard('web')->logout();

        $this->postJson('/api/v1/storefront/returns/lookup', ['order_number' => $order->order_number, 'email' => 'member@example.com'], ['X-Store-Slug' => $this->store->slug])->assertStatus(202);
        $this->assertSame(0, DB::table('return_guest_links')->count());

        // A customer record without an account (added by staff) has no sign-in: they get the link.
        $walkIn = Customer::factory()->for($this->store)->create(['email' => 'walkin@example.com', 'password' => null]);
        $this->app['auth']->forgetGuards();
        $second = $this->deliveredOrder(1, $walkIn);
        $this->app['auth']->forgetGuards();
        auth()->guard('web')->logout();
        $this->postJson('/api/v1/storefront/returns/lookup', ['order_number' => $second->order_number, 'email' => 'walkin@example.com'], ['X-Store-Slug' => $this->store->slug])->assertStatus(202);
        $this->assertSame(1, DB::table('return_guest_links')->count());
    }
}
