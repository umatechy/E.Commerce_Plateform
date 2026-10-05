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


    /** Phase B38: the demo catalogue in Urdu (product SKU => [name, short description]). */
    private const URDU_PRODUCTS = [
        'DEMO-ATR-001' => ['گلاب عطر 12 ملی لیٹر', 'طائف کے گلاب کی کلاسک خوشبو، دیرپا اور الکحل سے پاک۔'],
        'DEMO-ATR-002' => ['عود العرب 6 ملی لیٹر', 'شام کے لیے گہری، دھوئیں جیسی عود کی خوشبو۔'],
        'DEMO-ATR-003' => ['سفید مشک طہارہ 12 ملی لیٹر', 'روزمرہ کے لیے نرم اور صاف مشک۔'],
        'DEMO-ATR-004' => ['چنبیلی عطر 6 ملی لیٹر', 'رول آن بوتل میں تازہ چنبیلی کے پھول۔'],
        'DEMO-ATR-005' => ['عنبر گفٹ سیٹ (3 × 6 ملی لیٹر)', 'گفٹ باکس میں عنبر، عود اور گلاب۔ جان بوجھ کر کم اسٹاک۔'],
        'DEMO-MCL-001' => ['سفید سوتی کرتا', 'ہوادار سوتی کپڑا، عام فٹنگ۔'],
        'DEMO-MCL-002' => ['واش اینڈ وئیر شلوار قمیض', 'آسانی سے سنبھلنے والا کپڑا، اَن سِلا سوٹ۔'],
        'DEMO-MCL-003' => ['کالی واسکٹ', 'شادیوں اور عید کے لیے کلاسک واسکٹ۔'],
        'DEMO-MCL-004' => ['پشاوری چپل', 'ہاتھ سے بنی چمڑے کی چپل۔'],
        'DEMO-MCL-005' => ['خالص اونی شال', 'گرم اونی شال۔ جان بوجھ کر اسٹاک ختم۔'],
        'DEMO-HOM-001' => ['سرامک ٹی سیٹ (6 کپ)', 'چھ کپ، چھ پرچ اور ایک چائے دانی۔'],
        'DEMO-HOM-002' => ['اسٹیل کا دیگچہ 5 لیٹر', 'شیشے کے ڈھکن کے ساتھ اسٹین لیس اسٹیل۔'],
        'DEMO-HOM-003' => ['جائے نماز', 'سفری تھیلی میں نرم گدے دار جائے نماز۔'],
        'DEMO-HOM-004' => ['کشن کورز (4 کا سیٹ)', 'کڑھائی والے سوتی کور، 16 × 16 انچ۔'],
        'DEMO-HOM-005' => ['لکڑی کی دیوار گھڑی', 'بے آواز مشین، 12 انچ۔'],
    ];

    /** Category name => [Urdu name, Urdu description]. */
    private const URDU_CATEGORIES = [
        'Attar & Fragrances' => ['عطر اور خوشبوئیں', 'الکحل سے پاک عطر اور گفٹ سیٹ۔'],
        "Men's Clothing" => ['مردانہ لباس', 'روزمرہ اور تہوار کے کپڑے۔'],
        'Home & Kitchen' => ['گھر اور کچن', 'گھر کی کارآمد چیزیں۔'],
    ];

    /** The home page texts in Urdu, by section type. */
    private const URDU_SECTIONS = [
        'announcement_bar' => ['message' => 'یہ نمونے کی مصنوعات والا ڈیمو اسٹور ہے — آزادی سے دیکھیں، کارٹ میں ڈالیں اور چیک آؤٹ آزمائیں۔'],
        'hero' => ['heading' => 'ہر موقع کے لیے', 'subheading' => 'خوشبوئیں، کپڑے اور گھر کی چیزیں — اسٹور آزمانے کے لیے نمونے کا کیٹلاگ۔', 'cta_label' => 'کلیکشن دیکھیں'],
        'trust_badges' => ['items' => [
            ['title' => 'کیش آن ڈیلیوری', 'text' => 'آرڈر ملنے پر ادائیگی کریں'],
            ['title' => 'محفوظ چیک آؤٹ', 'text' => 'آپ کی تفصیلات اسٹور کے پاس رہتی ہیں'],
            ['title' => 'مدد کے لیے حاضر', 'text' => 'رابطہ کے صفحے سے ہمیں لکھیں'],
            ['title' => 'نمونے کا کیٹلاگ', 'text' => 'یہاں ہر چیز ڈیمو ڈیٹا ہے'],
        ]],
        'featured_categories' => ['heading' => 'زمرے کے لحاظ سے خریدیں'],
        'featured_products' => ['heading' => 'نئی آمد'],
        'sale_products' => ['heading' => 'ابھی سیل پر'],
        'best_sellers' => ['heading' => 'سب سے زیادہ فروخت'],
        'testimonials' => ['heading' => 'تبصروں کا حصہ ایسا دکھتا ہے', 'items' => [
            ['quote' => 'نمونے کا تبصرہ: خوشبو سارا دن رہی اور پیکنگ صاف ستھری تھی۔', 'name' => 'نمونہ گاہک', 'detail' => 'ڈیمو متن'],
            ['quote' => 'نمونے کا تبصرہ: کرتے کی فٹنگ اچھی ہے اور کپڑا نرم ہے۔', 'name' => 'نمونہ گاہک', 'detail' => 'ڈیمو متن'],
            ['quote' => 'نمونے کا تبصرہ: آرڈر دینا آسان تھا اور ادائیگی ڈیلیوری پر کی۔', 'name' => 'نمونہ گاہک', 'detail' => 'ڈیمو متن'],
        ]],
        'rich_text' => ['heading' => 'اس اسٹور کے بارے میں', 'text' => "یہ اسٹور دکھاتا ہے کہ پریمیم پیکج کے ساتھ پلیٹ فارم پر ایک دکان کیسی لگتی ہے: تھیم، اس کا لے آؤٹ اور اینیمیشن، اور ہوم پیج کے حصے۔\nیہاں ہر چیز، قیمت اور تبصرہ نمونے کا ڈیٹا ہے۔"],
        'faq' => ['heading' => 'سوالات', 'items' => [
            ['question' => 'کیا یہ اصلی اسٹور ہے؟', 'answer' => 'نہیں۔ یہ پلیٹ فارم کو آزمانے کے لیے نمونے کی مصنوعات والا ڈیمو اسٹور ہے۔'],
            ['question' => 'کیا میں آرڈر دے سکتا ہوں؟', 'answer' => 'جی ہاں۔ آرڈر کیش آن ڈیلیوری کے ساتھ کام کرتے ہیں، اس لیے کارٹ سے اکاؤنٹ تک پورا عمل آزمایا جا سکتا ہے۔'],
            ['question' => 'کیا شکل بدلی جا سکتی ہے؟', 'answer' => 'جی ہاں۔ ایڈمن میں تھیم سے مالک دوسری تھیم، اس کے رنگ، فونٹ، لے آؤٹ، اینیمیشن اور یہ حصے چن سکتا ہے۔'],
        ]],
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
        // Phase B38: the same texts in Urdu (shown when the store offers Urdu).
        $config['branding']['translations'] = ['ur' => ['tagline' => 'عطر، کپڑے اور گھر کی ضروری چیزیں']];
        foreach ($config['sections'] as $i => $section) {
            if (isset(self::URDU_SECTIONS[$section['type']])) {
                $config['sections'][$i]['config']['translations'] = ['ur' => self::URDU_SECTIONS[$section['type']]];
            }
        }
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
    /**
     * Phase B38: the storefront offers Urdu next to English, and the demo
     * catalogue has its Urdu names and descriptions.
     */
    public function dressLanguages(int $userId): void
    {
        app(\App\Domain\Settings\Services\ConfigService::class)->set('store.languages', ['en', 'ur'], \App\Domain\Settings\Models\SettingScope::Store, $userId ?: null, 'Demo store: Urdu');
        $translations = app(\App\Domain\Settings\Services\TranslationService::class);
        foreach (\App\Domain\Catalog\Models\Product::query()->whereIn('sku', array_keys(self::URDU_PRODUCTS))->get() as $product) {
            [$name, $summary] = self::URDU_PRODUCTS[$product->sku];
            $translations->save('product', $product->id, 'ur', ['name' => $name, 'short_description' => $summary, 'description' => $summary."\nیہ اسٹور آزمانے کے لیے ڈیمو پروڈکٹ ہے۔"], $userId ?: null);
        }
        foreach (\App\Domain\Catalog\Models\Category::query()->whereIn('name', array_keys(self::URDU_CATEGORIES))->get() as $category) {
            [$name, $description] = self::URDU_CATEGORIES[$category->name];
            $translations->save('category', $category->id, 'ur', ['name' => $name, 'description' => $description], $userId ?: null);
        }
    }

    /**
     * Phase B43: featured products with sort priorities, two badges of the
     * store's own (with their Urdu labels) and "featured first" as the
     * listing order — so the demo shows badges and merchandising at work.
     * Safe to run again.
     */
    public function dressMerchandising(int $userId): void
    {
        $products = \App\Domain\Catalog\Models\Product::query()->whereIn('sku', ['DEMO-ATR-001', 'DEMO-MCL-004', 'DEMO-HOM-001'])->get()->keyBy('sku');
        foreach (['DEMO-ATR-001' => 30, 'DEMO-MCL-004' => 20, 'DEMO-HOM-001' => 10] as $sku => $priority) {
            $products->get($sku)?->update(['is_featured' => true, 'sort_priority' => $priority]);
        }
        $translations = app(\App\Domain\Settings\Services\TranslationService::class);
        foreach ([['Handmade', 'success', 95, 'ہاتھ سے بنا', 'DEMO-MCL-004'], ['Eid special', 'accent', 55, 'عید اسپیشل', 'DEMO-ATR-005']] as [$label, $tone, $priority, $urdu, $sku]) {
            $badge = \App\Domain\Catalog\Models\Badge::query()->firstOrCreate(['label' => $label], ['tone' => $tone, 'priority' => $priority]);
            $translations->save('badge', $badge->id, 'ur', ['label' => $urdu], $userId ?: null);
            $product = \App\Domain\Catalog\Models\Product::query()->where('sku', $sku)->first();
            $product?->badges()->syncWithoutDetaching([$badge->id]);
            $product?->touch();
        }
        app(\App\Domain\Settings\Services\ConfigService::class)->set('catalog.default_sort', 'featured', \App\Domain\Settings\Models\SettingScope::Store, $userId ?: null, 'Demo store: featured first');
    }
}
