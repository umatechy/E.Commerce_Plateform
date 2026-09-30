<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Domain\Catalog\Models\ProductImage;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase B24 — product images: every upload is re-encoded (metadata such
 * as GPS positions is dropped, disguised files are refused), ordered,
 * tenant-isolated and published on the storefront.
 */
final class ProductImageTest extends TestCase
{
    use InteractsWithStorefront, RefreshDatabase;

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

    /** A real JPEG carrying an EXIF block with a (fake) GPS position. */
    private function jpegWithExif(): UploadedFile
    {
        $image = imagecreatetruecolor(400, 300);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();
        $exif = "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08GPS 31.5204N 74.3587E home-of-seller";
        $withExif = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
        $path = tempnam(sys_get_temp_dir(), 'img');
        file_put_contents($path, $withExif);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public function test_an_upload_is_reencoded_without_its_metadata_and_shown_on_the_storefront(): void
    {
        $store = $this->openStore();
        $product = $this->product($store, ['slug' => 'lamp']);
        $upload = $this->jpegWithExif();
        $this->assertStringContainsString('home-of-seller', (string) file_get_contents($upload->getRealPath()));

        $response = $this->actingAs($this->owner($store))
            ->post("/api/v1/products/{$product->id}/images", ['image' => $upload, 'alt' => 'Brass lamp'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.width', 400)
            ->assertJsonPath('data.alt', 'Brass lamp');

        $image = ProductImage::query()->sole();
        $stored = Storage::disk('public')->get($image->path);
        $this->assertStringNotContainsString('home-of-seller', $stored);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($stored)[2]);
        $this->assertStringStartsWith("stores/{$store->public_id}/products/{$product->public_id}/", $image->path);

        $this->getJson('/api/v1/storefront/products/lamp', $this->storefront($store))->assertOk()
            ->assertJsonPath('data.product.images.0.alt', 'Brass lamp')
            ->assertJsonPath('data.product.images.0.url', $response->json('data.url'));
    }

    public function test_files_that_are_not_real_images_are_refused(): void
    {
        $store = $this->openStore();
        $product = $this->product($store);
        $owner = $this->owner($store);
        $fake = UploadedFile::fake()->createWithContent('shell.jpg', "\xFF\xD8\xFF\xE0<html><script>alert(1)</script>");
        $tiny = UploadedFile::fake()->image('tiny.png', 20, 20);
        $text = UploadedFile::fake()->create('notes.txt', 1, 'text/plain');

        foreach ([$fake, $tiny, $text] as $file) {
            $this->actingAs($owner)->post("/api/v1/products/{$product->id}/images", ['image' => $file], ['Accept' => 'application/json'])
                ->assertStatus(422)->assertJsonValidationErrors('image');
        }

        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_images_are_reordered_and_deleted_with_their_files(): void
    {
        $store = $this->openStore();
        $product = $this->product($store);
        $owner = $this->owner($store);
        $ids = [];
        foreach (['a', 'b', 'c'] as $name) {
            $ids[] = $this->actingAs($owner)->post("/api/v1/products/{$product->id}/images", ['image' => UploadedFile::fake()->image("{$name}.png", 200, 200)], ['Accept' => 'application/json'])
                ->assertCreated()->json('data.id');
        }

        $this->actingAs($owner)->putJson("/api/v1/products/{$product->id}/images/order", ['images' => [$ids[2], $ids[0], $ids[1]]])
            ->assertOk()->assertJsonPath('data.0.id', $ids[2]);
        $this->actingAs($owner)->putJson("/api/v1/products/{$product->id}/images/order", ['images' => [$ids[0]]])
            ->assertStatus(422);

        $path = ProductImage::query()->where('public_id', $ids[1])->value('path');
        $this->actingAs($owner)->deleteJson("/api/v1/products/{$product->id}/images/{$ids[1]}")->assertNoContent();
        Storage::disk('public')->assertMissing($path);
        $this->assertSame(2, ProductImage::query()->count());
    }

    public function test_image_management_is_tenant_isolated_and_permission_gated(): void
    {
        $store = $this->openStore();
        $product = $this->product($store);
        $other = $this->openStore();
        $otherOwner = $this->owner($other);
        $viewer = User::factory()->create();
        $store->users()->attach($viewer, ['role_id' => Role::factory()->for($store)->create(['slug' => 'viewer'])->id, 'status' => 'active']);

        $this->actingAs($otherOwner)->post("/api/v1/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('x.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertNotFound();
        $this->actingAs($viewer)->post("/api/v1/products/{$product->id}/images", ['image' => UploadedFile::fake()->image('x.png', 200, 200)], ['Accept' => 'application/json'])
            ->assertForbidden();
    }
}
