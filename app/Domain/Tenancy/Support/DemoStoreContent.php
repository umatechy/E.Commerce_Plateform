<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Support;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductImageService;
use App\Domain\Theme\Models\StoreTheme;
use App\Domain\Theme\Services\ThemeService;
use Illuminate\Http\UploadedFile;

/**
 * Phase B36: what makes the demo store (php artisan demo:store) look like a
 * real shop — product pictures and a published theme with home page
 * sections. Demo data only; the command refuses to run in production.
 *
 * The pictures are drawn here with GD: a colour gradient and a simple shape
 * for the category (a bottle, a shirt, a cup), no text, and they go through
 * ProductImageService like any upload (re-encoded, size-checked). Their alt
 * text says they are demo images. The home page texts say plainly that this
 * is a demo; nothing claims a delivery promise, a review or a policy the
 * store has not set.
 */
final class DemoStoreContent
{
    /** Category name => [colour from, colour to, shape]. */
    private const LOOK = [
        'Attar & Fragrances' => [[247, 231, 206], [176, 122, 63], 'bottle'],
        "Men's Clothing" => [[226, 232, 240], [51, 65, 85], 'shirt'],
        'Home & Kitchen' => [[220, 237, 225], [63, 111, 82], 'cup'],
    ];

    public function __construct(private readonly ProductImageService $images, private readonly ThemeService $themes) {}

    /** Two pictures for a product that has none (the second is shown on hover). */
    public function picture(Product $product, string $categoryName, int $seed): int
    {
        if ($product->images()->exists()) {
            return 0;
        }
        [$from, $to, $shape] = self::LOOK[$categoryName] ?? [[230, 230, 230], [90, 90, 90], 'cup'];
        $shape = self::shapeFor($product->name, $shape);
        // A little colour variety between products of one category.
        $shift = [($seed * 23) % 41 - 20, ($seed * 11) % 31 - 15, ($seed * 17) % 37 - 18];
        $to = array_map(fn (int $c, int $d) => max(0, min(255, $c + $d)), $to, $shift);
        $added = 0;
        foreach ([false, true] as $alternate) {
            $path = $this->draw($from, $to, $shape, $seed, $alternate);
            try {
                $this->images->add($product, new UploadedFile($path, "demo-{$seed}.png", 'image/png', null, true), "Demo image of {$product->name}", null);
                $added++;
            } finally {
                @unlink($path);
            }
        }

        return $added;
    }

    /** Selects the theme for the draft, sets the demo home page, publishes. */
    public function dressTheme(StoreTheme $storeTheme, string $themeKey, ?int $actorUserId): void
    {
        $storeTheme = $this->themes->selectTheme($storeTheme, $themeKey, $actorUserId);
        $config = $storeTheme->draft_config;
        $config['branding']['tagline'] = 'Attars, clothing and home essentials';
        $config['sections'] = [
            ['type' => 'header', 'position' => 0, 'is_visible' => true, 'config' => []],
            ['type' => 'announcement_bar', 'position' => 1, 'is_visible' => true, 'config' => ['message' => 'This is a demo store with sample products — browse, add to cart and test checkout freely.']],
            ['type' => 'hero', 'position' => 2, 'is_visible' => true, 'config' => ['heading' => 'Crafted for every occasion', 'subheading' => 'Fragrances, clothing and things for the home — a sample catalogue to try the store with.', 'cta_label' => 'Shop the collection']],
            ['type' => 'trust_badges', 'position' => 3, 'is_visible' => true, 'config' => ['items' => [
                ['icon' => 'cod', 'title' => 'Cash on delivery', 'text' => 'Pay when your order arrives'],
                ['icon' => 'secure', 'title' => 'Safe checkout', 'text' => 'Your details stay with the store'],
                ['icon' => 'support', 'title' => 'Here to help', 'text' => 'Write to us from the Contact page'],
                ['icon' => 'quality', 'title' => 'Sample catalogue', 'text' => 'Every product here is demo data'],
            ]]],
            ['type' => 'featured_categories', 'position' => 4, 'is_visible' => true, 'config' => ['heading' => 'Shop by category', 'limit' => 6]],
            ['type' => 'featured_products', 'position' => 5, 'is_visible' => true, 'config' => ['heading' => 'New arrivals', 'limit' => 8]],
            ['type' => 'sale_products', 'position' => 6, 'is_visible' => true, 'config' => ['heading' => 'On sale now', 'limit' => 4]],
            ['type' => 'best_sellers', 'position' => 7, 'is_visible' => true, 'config' => ['heading' => 'Best sellers', 'limit' => 4]],
            ['type' => 'testimonials', 'position' => 8, 'is_visible' => true, 'config' => ['heading' => 'What a review section looks like', 'items' => [
                ['quote' => 'Sample review: the fragrance lasted all day and the packing was neat.', 'name' => 'Sample customer', 'detail' => 'Demo text'],
                ['quote' => 'Sample review: the kurta fits well and the cotton feels soft.', 'name' => 'Sample customer', 'detail' => 'Demo text'],
                ['quote' => 'Sample review: ordering was simple and I paid on delivery.', 'name' => 'Sample customer', 'detail' => 'Demo text'],
            ]]],
            ['type' => 'rich_text', 'position' => 9, 'is_visible' => true, 'config' => ['heading' => 'About this store', 'text' => "This store shows what a shop on the platform looks like with the Premium package: a theme, its layout and animation, and the home page sections.\nEvery product, price and review here is sample data."]],
            ['type' => 'faq', 'position' => 10, 'is_visible' => true, 'config' => ['heading' => 'Questions', 'items' => [
                ['question' => 'Is this a real store?', 'answer' => 'No. It is a demo store with sample products, made for testing the platform.'],
                ['question' => 'Can I place an order?', 'answer' => 'Yes. Orders work with cash on delivery, so the whole flow can be tested from cart to account.'],
                ['question' => 'Can the look be changed?', 'answer' => 'Yes. In the admin, Theme lets the owner choose another theme, its colours, fonts, layout, animation and these sections.'],
            ]]],
            ['type' => 'footer', 'position' => 99, 'is_visible' => true, 'config' => []],
        ];
        $storeTheme = $this->themes->updateDraft($storeTheme, $config, null, $actorUserId);
        $this->themes->publish($storeTheme, $actorUserId);
    }

    /**
     * @param array{int, int, int} $from
     * @param array{int, int, int} $to
     */
    private function draw(array $from, array $to, string $shape, int $seed, bool $alternate): string
    {
        $size = 800;
        $image = imagecreatetruecolor($size, $size);
        // Vertical gradient (the alternate picture runs the other way).
        for ($y = 0; $y < $size; $y++) {
            $t = $alternate ? 1 - $y / $size : $y / $size;
            $t = 0.15 + $t * 0.55;
            $color = imagecolorallocate($image, ...array_map(fn (int $a, int $b) => (int) round($a + ($b - $a) * $t), $from, $to));
            imageline($image, 0, $y, $size, $y, $color);
        }
        $light = imagecolorallocatealpha($image, 255, 255, 255, 80);
        $dark = imagecolorallocate($image, ...array_map(fn (int $c) => (int) round($c * 0.55), $to));
        $accent = imagecolorallocate($image, ...array_map(fn (int $c) => min(255, (int) round($c * 1.15)), $from));
        // A soft disc behind the object, placed by the seed.
        $cx = 400 + (($seed * 37) % 120) - 60;
        imagefilledellipse($image, $cx, $alternate ? 460 : 380, 560, 560, $light);

        match ($shape) {
            'bottle' => $this->bottle($image, $dark, $accent, $alternate),
            'shirt' => $this->shirt($image, $dark, $accent, $alternate),
            'vest' => $this->vest($image, $dark, $accent),
            'shoe' => $this->shoe($image, $dark, $accent, $alternate),
            'folded' => $this->folded($image, $dark, $accent),
            'box' => $this->box($image, $dark, $accent),
            'clock' => $this->clock($image, $dark, $accent),
            'pot' => $this->pot($image, $dark, $accent),
            default => $this->cup($image, $dark, $accent, $alternate),
        };

        $path = tempnam(sys_get_temp_dir(), 'demo').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function bottle(\GdImage $image, int $dark, int $accent, bool $alternate): void
    {
        $x = $alternate ? 330 : 300;
        imagefilledrectangle($image, $x + 70, 220, $x + 130, 300, $dark);   // neck
        imagefilledrectangle($image, $x + 55, 180, $x + 145, 230, $accent); // cap
        imagefilledellipse($image, $x + 100, 470, 260, 340, $dark);         // body
        imagefilledellipse($image, $x + 70, 420, 50, 140, $accent);         // shine
    }

    private function shirt(\GdImage $image, int $dark, int $accent, bool $alternate): void
    {
        $o = $alternate ? 30 : 0;
        imagefilledpolygon($image, [250 + $o, 250, 340 + $o, 210, 460 + $o, 210, 550 + $o, 250, 620 + $o, 360, 545 + $o, 400, 520 + $o, 360, 520 + $o, 620, 280 + $o, 620, 280 + $o, 360, 255 + $o, 400, 180 + $o, 360], $dark);
        imagefilledpolygon($image, [360 + $o, 210, 400 + $o, 270, 440 + $o, 210], $accent);
        imagefilledrectangle($image, 395 + $o, 280, 405 + $o, 600, $accent);
    }

    private function cup(\GdImage $image, int $dark, int $accent, bool $alternate): void
    {
        $o = $alternate ? -20 : 0;
        imagefilledellipse($image, 400 + $o, 600, 420, 70, $accent);                                    // saucer
        imagefilledpolygon($image, [270 + $o, 340, 530 + $o, 340, 500 + $o, 580, 300 + $o, 580], $dark); // cup
        imagefilledellipse($image, 400 + $o, 340, 260, 50, $accent);                                    // rim
        imagesetthickness($image, 22);
        imagearc($image, 545 + $o, 440, 110, 120, 270, 90, $dark);                                      // handle
    }
    /** The drawing that fits the product's name best; the category's own otherwise. */
    private static function shapeFor(string $name, string $fallback): string
    {
        $name = mb_strtolower($name);
        foreach (['gift' => 'box', 'set (3' => 'box', 'waistcoat' => 'vest', 'chappal' => 'shoe', 'shawl' => 'folded', 'mat' => 'folded', 'cushion' => 'box', 'clock' => 'clock', 'pot' => 'pot'] as $word => $shape) {
            if (str_contains($name, $word)) {
                return $shape;
            }
        }

        return $fallback;
    }

    private function vest(\GdImage $image, int $dark, int $accent): void
    {
        imagefilledpolygon($image, [300, 210, 370, 210, 400, 330, 430, 210, 500, 210, 540, 300, 520, 620, 280, 620, 260, 300], $dark);
        foreach ([380, 440, 500, 560] as $y) {
            imagefilledellipse($image, 412, $y, 16, 16, $accent);
        }
    }

    private function shoe(\GdImage $image, int $dark, int $accent, bool $alternate): void
    {
        $o = $alternate ? 30 : 0;
        imagefilledellipse($image, 400 + $o, 520, 470, 150, $dark);
        imagefilledpolygon($image, [250 + $o, 520, 330 + $o, 430, 480 + $o, 430, 560 + $o, 520], $accent);
        imagefilledrectangle($image, 180 + $o, 560, 620 + $o, 590, $dark);
    }

    private function folded(\GdImage $image, int $dark, int $accent): void
    {
        foreach ([[250, 480, 550, 560], [270, 400, 530, 480], [290, 320, 510, 400]] as $i => [$x1, $y1, $x2, $y2]) {
            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $i % 2 === 0 ? $dark : $accent);
        }
    }

    private function box(\GdImage $image, int $dark, int $accent): void
    {
        imagefilledrectangle($image, 260, 340, 540, 600, $dark);
        imagefilledrectangle($image, 240, 290, 560, 350, $dark);
        imagefilledrectangle($image, 385, 290, 415, 600, $accent);
        imagefilledellipse($image, 360, 280, 90, 60, $accent);
        imagefilledellipse($image, 440, 280, 90, 60, $accent);
    }

    private function clock(\GdImage $image, int $dark, int $accent): void
    {
        imagefilledellipse($image, 400, 420, 380, 380, $dark);
        imagefilledellipse($image, 400, 420, 320, 320, $accent);
        imagesetthickness($image, 14);
        imageline($image, 400, 420, 400, 300, $dark);
        imageline($image, 400, 420, 480, 470, $dark);
        imagefilledellipse($image, 400, 420, 30, 30, $dark);
    }

    private function pot(\GdImage $image, int $dark, int $accent): void
    {
        imagefilledrectangle($image, 250, 380, 550, 590, $dark);
        imagefilledellipse($image, 400, 590, 300, 50, $dark);
        imagefilledellipse($image, 400, 370, 340, 60, $accent);
        imagefilledrectangle($image, 380, 320, 420, 350, $accent);
        imagefilledrectangle($image, 190, 420, 250, 445, $dark);
        imagefilledrectangle($image, 550, 420, 610, 445, $dark);
    }
}
