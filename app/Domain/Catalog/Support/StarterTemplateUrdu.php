<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

/**
 * Phase B45 follow-up — Module 07 §38 (taxonomy localization), Module 31
 * (Urdu storefronts, B38): the Urdu text of the built-in starter templates,
 * as one glossary keyed by the English text. One entry serves every template
 * that uses the word ("Colour", "Black", "Gifts"), so nothing is translated
 * twice and two templates never give one word two translations.
 *
 * When a template creates a category, an attribute or a value, its Urdu
 * text is saved as the item's `ur` translation (content_translations), so a
 * store that offers Urdu shows it at once; a store that does not keeps it
 * until it does. The owner can change any of it under Translate. Items the
 * store already had are never touched.
 *
 * Plain sizes and numbers (XS, 128 GB, UK 7) are the same in both languages
 * and are not listed. A test checks that every category, attribute and
 * worded value of every built-in template has an entry here.
 */
final class StarterTemplateUrdu
{
    public const TEXT = [
        // Clothing & fashion
        'Women' => 'خواتین', 'Kurtas, suits, dupattas and everyday wear for women.' => 'خواتین کے لیے کرتے، سوٹ، دوپٹے اور روزمرہ کے کپڑے۔',
        'Kurtas & kurtis' => 'کرتے اور کرتیاں', 'Unstitched suits' => 'اَن سِلے سوٹ', 'Dupattas & shawls' => 'دوپٹے اور شالیں', 'Western wear' => 'ویسٹرن لباس',
        'Men' => 'مرد', 'Shalwar kameez, kurtas, shirts and waistcoats for men.' => 'مردوں کے لیے شلوار قمیض، کرتے، شرٹس اور واسکٹ۔',
        'Shalwar kameez' => 'شلوار قمیض', 'Kurtas' => 'کرتے', 'Shirts & T-shirts' => 'شرٹس اور ٹی شرٹس', 'Waistcoats' => 'واسکٹ',
        'Kids' => 'بچے', 'Clothes for girls and boys.' => 'لڑکیوں اور لڑکوں کے کپڑے۔', 'Girls' => 'لڑکیاں', 'Boys' => 'لڑکے',
        'Accessories' => 'لوازمات', 'Bags, belts, caps and scarves.' => 'بیگ، بیلٹ، ٹوپیاں اور اسکارف۔', 'Bags' => 'بیگ', 'Belts' => 'بیلٹ', 'Caps & scarves' => 'ٹوپیاں اور اسکارف',
        'Size' => 'سائز', 'Colour' => 'رنگ', 'Fabric' => 'کپڑا', 'Fit' => 'فٹنگ', 'Stitching' => 'سلائی', 'Pieces' => 'پیس', 'Care instructions' => 'دیکھ بھال کی ہدایات',
        'Black' => 'سیاہ', 'White' => 'سفید', 'Grey' => 'سرمئی', 'Navy' => 'نیوی بلیو', 'Blue' => 'نیلا', 'Red' => 'سرخ', 'Maroon' => 'میرون', 'Pink' => 'گلابی',
        'Green' => 'سبز', 'Olive' => 'زیتونی', 'Beige' => 'بیج', 'Brown' => 'بھورا', 'Mustard' => 'سرسوں رنگ', 'Purple' => 'جامنی',
        'Cotton' => 'سوتی', 'Lawn' => 'لان', 'Khaddar' => 'کھدر', 'Linen' => 'لینن', 'Cambric' => 'کیمبرک', 'Silk' => 'ریشم', 'Chiffon' => 'شیفون', 'Wool' => 'اون', 'Polyester' => 'پولیسٹر',
        'Regular' => 'عام', 'Slim' => 'سلم', 'Loose' => 'کھلی', 'Stitched' => 'سِلا ہوا', 'Unstitched' => 'اَن سِلا',
        '1 piece' => 'ایک پیس', '2 piece' => 'دو پیس', '3 piece' => 'تین پیس',

        // Shoes & footwear
        'Sneakers, formal shoes, sandals and chappals for men.' => 'مردوں کے لیے جوگرز، فارمل جوتے، سینڈل اور چپلیں۔', 'Sneakers' => 'جوگرز',
        'Formal shoes' => 'فارمل جوتے', 'Sandals & chappals' => 'سینڈل اور چپلیں',
        'Heels, flats, khussas and sneakers for women.' => 'خواتین کے لیے ہیلز، فلیٹس، کھسے اور جوگرز۔', 'Heels' => 'ہیلز', 'Flats & khussas' => 'فلیٹس اور کھسے',
        'Shoes for girls and boys.' => 'لڑکیوں اور لڑکوں کے جوتے۔', 'Socks & shoe care' => 'جرابیں اور جوتوں کی دیکھ بھال', 'Socks, polish and care kits.' => 'جرابیں، پالش اور دیکھ بھال کی کٹس۔',
        'Shoe size (UK)' => 'جوتے کا سائز (UK)', 'Upper material' => 'اوپری حصے کا میٹیریل', 'Sole' => 'تلا', 'Closure' => 'بندش',
        'Leather' => 'چمڑا', 'Synthetic leather' => 'مصنوعی چمڑا', 'Suede' => 'سوئیڈ', 'Canvas' => 'کینوس', 'Mesh' => 'میش', 'Rubber' => 'ربڑ',
        'Lace-up' => 'تسموں والے', 'Slip-on' => 'بغیر تسمے', 'Velcro' => 'ویلکرو', 'Buckle' => 'بکل',

        // Beauty & personal care
        'Skincare' => 'جلد کی دیکھ بھال', 'Cleansers, moisturisers, sunscreen and serums.' => 'کلینزر، موئسچرائزر، سن اسکرین اور سیرم۔',
        'Cleansers' => 'کلینزر', 'Moisturisers' => 'موئسچرائزر', 'Sunscreen' => 'سن اسکرین', 'Serums' => 'سیرم',
        'Makeup' => 'میک اپ', 'Face, eye and lip makeup.' => 'چہرے، آنکھوں اور ہونٹوں کا میک اپ۔', 'Face' => 'چہرہ', 'Eyes' => 'آنکھیں', 'Lips' => 'ہونٹ',
        'Hair care' => 'بالوں کی دیکھ بھال', 'Shampoos, conditioners, oils and styling.' => 'شیمپو، کنڈیشنر، تیل اور اسٹائلنگ۔',
        'Bath & body' => 'نہانے اور جسم کی دیکھ بھال', 'Soaps, body wash and lotions.' => 'صابن، باڈی واش اور لوشن۔',
        "Men's grooming" => 'مردانہ گرومنگ', 'Shaving, beard care and grooming.' => 'شیو، داڑھی کی دیکھ بھال اور گرومنگ۔',
        'Skin type' => 'جلد کی قسم', 'Normal' => 'نارمل', 'Dry' => 'خشک', 'Oily' => 'چکنی', 'Combination' => 'ملی جلی', 'Sensitive' => 'حساس',
        'Concern' => 'مسئلہ', 'Acne' => 'کیل مہاسے', 'Dryness' => 'خشکی', 'Dark spots' => 'سیاہ دھبے', 'Dullness' => 'بے رونقی', 'Fine lines' => 'باریک لکیریں', 'Sun protection' => 'دھوپ سے بچاؤ',
        'Hair type' => 'بالوں کی قسم', 'Straight' => 'سیدھے', 'Wavy' => 'لہردار', 'Curly' => 'گھنگریالے', 'Coloured' => 'رنگے ہوئے',
        'Fragrance free' => 'خوشبو کے بغیر', 'Ingredients' => 'اجزاء',

        // Perfume & attar
        'Attar' => 'عطر', 'Concentrated perfume oils.' => 'خوشبو کے گاڑھے تیل۔', 'Perfumes' => 'پرفیوم', 'Eau de parfum and eau de toilette.' => 'او ڈی پرفیوم اور او ڈی ٹوائلٹ۔',
        'Body mists' => 'باڈی مسٹ', 'Light, fresh body sprays.' => 'ہلکے، تازہ باڈی اسپرے۔', 'Gift sets' => 'گفٹ سیٹ', 'Fragrance gift boxes.' => 'خوشبوؤں کے گفٹ باکس۔',
        'Bakhoor & incense' => 'بخور اور اگربتی', 'Bakhoor, oud chips and burners.' => 'بخور، عود کی لکڑی اور بخوردان۔',
        'Volume' => 'مقدار', 'Type' => 'قسم', 'Attar (oil)' => 'عطر (تیل)', 'Eau de parfum' => 'او ڈی پرفیوم', 'Eau de toilette' => 'او ڈی ٹوائلٹ', 'Body mist' => 'باڈی مسٹ',
        'Scent family' => 'خوشبو کی قسم', 'Floral' => 'پھولوں والی', 'Woody' => 'لکڑی والی', 'Oud' => 'عود', 'Musk' => 'مشک', 'Amber' => 'عنبر',
        'Citrus' => 'لیموں والی', 'Fresh' => 'تازہ', 'Spicy' => 'مصالحے دار', 'Sweet' => 'میٹھی', 'For' => 'کس کے لیے', 'Unisex' => 'سب کے لیے',
        'Alcohol free' => 'الکحل سے پاک', 'Notes' => 'خوشبو کے نوٹس',

        // Jewellery & accessories
        'Earrings' => 'بالیاں اور جھمکے', 'Studs, jhumkas and drops.' => 'ٹاپس، جھمکے اور لٹکن۔', 'Necklaces & sets' => 'ہار اور سیٹ', 'Necklaces, chains and matching sets.' => 'ہار، زنجیریں اور میچنگ سیٹ۔',
        'Bangles & bracelets' => 'چوڑیاں اور بریسلٹ', 'Bangles, kangans and bracelets.' => 'چوڑیاں، کنگن اور بریسلٹ۔', 'Rings' => 'انگوٹھیاں', 'Rings for every day and special days.' => 'روزمرہ اور خاص دنوں کی انگوٹھیاں۔',
        'Bridal sets' => 'دلہن کے سیٹ', 'Complete bridal jewellery.' => 'دلہن کے مکمل زیورات۔', 'Watches & hair accessories' => 'گھڑیاں اور بالوں کے لوازمات', 'Watches, clips and hair pins.' => 'گھڑیاں، کلپس اور ہیئر پنز۔',
        'Metal' => 'دھات', 'Gold' => 'سونا', 'Silver' => 'چاندی', 'Gold plated' => 'سونے کا پانی', 'Silver plated' => 'چاندی کا پانی', 'Brass' => 'پیتل', 'Alloy' => 'مخلوط دھات',
        'Stone' => 'نگینہ', 'None' => 'کوئی نہیں', 'Zircon' => 'زرقون', 'Pearl' => 'موتی', 'Kundan' => 'کندن', 'Crystal' => 'کرسٹل', 'Gemstone' => 'قیمتی پتھر',
        'Rose gold' => 'روز گولڈ', 'Multicolour' => 'رنگ برنگا', 'Occasion' => 'موقع', 'Daily wear' => 'روزمرہ', 'Party' => 'تقریب', 'Bridal' => 'دلہن', 'Weight' => 'وزن',

        // Mobiles & electronics
        'Mobile phones' => 'موبائل فون', 'Smartphones and feature phones.' => 'اسمارٹ فون اور سادہ فون۔', 'Tablets' => 'ٹیبلٹ', 'Tablets and e-readers.' => 'ٹیبلٹ اور ای ریڈر۔',
        'Laptops' => 'لیپ ٹاپ', 'Laptops and notebooks.' => 'لیپ ٹاپ اور نوٹ بک۔', 'Smart watches' => 'اسمارٹ واچ', 'Smart watches and fitness bands.' => 'اسمارٹ واچ اور فٹنس بینڈ۔',
        'Chargers, earphones, covers and power banks.' => 'چارجر، ایئر فون، کور اور پاور بینک۔', 'Chargers & cables' => 'چارجر اور کیبل', 'Earphones & headphones' => 'ایئر فون اور ہیڈ فون',
        'Covers & protectors' => 'کور اور پروٹیکٹر', 'Power banks' => 'پاور بینک',
        'Storage' => 'اسٹوریج', 'RAM' => 'ریم', 'Screen size' => 'اسکرین کا سائز', 'Condition' => 'حالت', 'New' => 'نیا', 'Open box' => 'اوپن باکس', 'Used' => 'استعمال شدہ', 'Refurbished' => 'ری فربشڈ',
        'PTA approved' => 'پی ٹی اے سے منظور شدہ', 'Warranty' => 'وارنٹی', 'No warranty' => 'وارنٹی نہیں', 'Shop warranty' => 'دکان کی وارنٹی', 'Brand warranty' => 'کمپنی کی وارنٹی',

        // Home, kitchen & furniture
        'Kitchen & dining' => 'کچن اور کھانے کا سامان', 'Cookware, dinnerware and storage.' => 'پکانے کے برتن، کھانے کے برتن اور ڈبے۔', 'Cookware' => 'پکانے کے برتن', 'Dinnerware' => 'کھانے کے برتن',
        'Storage & containers' => 'ڈبے اور کنٹینر', 'Home décor' => 'گھر کی سجاوٹ', 'Wall décor, cushions and lighting.' => 'دیوار کی سجاوٹ، کشن اور روشنیاں۔', 'Wall décor' => 'دیوار کی سجاوٹ',
        'Cushions & throws' => 'کشن اور تھرو', 'Lighting' => 'روشنیاں', 'Bedding & bath' => 'بستر اور غسل خانہ', 'Bed sheets, towels and bath mats.' => 'بیڈ شیٹس، تولیے اور باتھ میٹ۔',
        'Furniture' => 'فرنیچر', 'Tables, chairs, beds and storage.' => 'میزیں، کرسیاں، بیڈ اور الماریاں۔', 'Cleaning & laundry' => 'صفائی اور دھلائی', 'Everything to keep the home clean.' => 'گھر صاف رکھنے کی ہر چیز۔',
        'Material' => 'میٹیریل', 'Wood' => 'لکڑی', 'Steel' => 'اسٹیل', 'Ceramic' => 'سرامک', 'Glass' => 'شیشہ', 'Plastic' => 'پلاسٹک', 'Marble' => 'سنگِ مرمر',
        'Room' => 'کمرہ', 'Living room' => 'بیٹھک', 'Bedroom' => 'سونے کا کمرہ', 'Kitchen' => 'کچن', 'Bathroom' => 'غسل خانہ', 'Outdoor' => 'باہر کے لیے',
        'Pieces in the set' => 'سیٹ میں پیس', 'Dimensions' => 'پیمائش',

        // Grocery & daily needs
        'Fruits & vegetables' => 'پھل اور سبزیاں', 'Fresh fruit and vegetables.' => 'تازہ پھل اور سبزیاں۔', 'Dairy & eggs' => 'دودھ کی اشیاء اور انڈے', 'Milk, yoghurt, butter, cheese and eggs.' => 'دودھ، دہی، مکھن، پنیر اور انڈے۔',
        'Rice, flour & pulses' => 'چاول، آٹا اور دالیں', 'Rice, atta, daal and grains.' => 'چاول، آٹا، دال اور اناج۔', 'Cooking oil & ghee' => 'کوکنگ آئل اور گھی', 'Oil, ghee and banaspati.' => 'تیل، گھی اور بناسپتی۔',
        'Spices & masala' => 'مصالحے', 'Whole spices and ready masala mixes.' => 'ثابت مصالحے اور تیار مصالحے۔', 'Tea & beverages' => 'چائے اور مشروبات', 'Tea, coffee, juices and drinks.' => 'چائے، کافی، جوس اور مشروبات۔',
        'Snacks & biscuits' => 'اسنیکس اور بسکٹ', 'Biscuits, chips and namkeen.' => 'بسکٹ، چپس اور نمکو۔', 'Household & cleaning' => 'گھریلو اور صفائی کا سامان', 'Detergents, dishwash and cleaners.' => 'سرف، برتن دھونے کا سامان اور کلینر۔',
        'Net weight' => 'خالص وزن', 'Dietary' => 'غذائی', 'Sugar free' => 'شوگر فری', 'Gluten free' => 'گلوٹن فری', 'Organic' => 'آرگینک', 'Vegetarian' => 'سبزی خور',
        'Keep' => 'رکھنے کا طریقہ', 'Room temperature' => 'عام درجہ حرارت', 'Chilled' => 'ٹھنڈا', 'Frozen' => 'منجمد',

        // Food, bakery & sweets
        'Cakes' => 'کیک', 'Birthday, celebration and tea cakes.' => 'سالگرہ، تقریبات اور چائے کے کیک۔', 'Mithai & sweets' => 'مٹھائی', 'Traditional mithai by weight and in boxes.' => 'روایتی مٹھائی، وزن کے حساب سے اور ڈبوں میں۔',
        'Bakery & bread' => 'بیکری اور ڈبل روٹی', 'Bread, buns and pastries.' => 'ڈبل روٹی، بن اور پیسٹریاں۔', 'Cookies & rusks' => 'کوکیز اور رس', 'Cookies, biscuits and rusks.' => 'کوکیز، بسکٹ اور رس۔',
        'Savoury & snacks' => 'نمکین اور اسنیکس', 'Samosas, patties, nimko and more.' => 'سموسے، پیٹیز، نمکو اور بہت کچھ۔', 'Gift boxes' => 'گفٹ باکس', 'Boxes for Eid, weddings and gifts.' => 'عید، شادیوں اور تحفوں کے لیے ڈبے۔',
        'Flavour' => 'ذائقہ', 'Chocolate' => 'چاکلیٹ', 'Vanilla' => 'ونیلا', 'Strawberry' => 'اسٹرابیری', 'Pineapple' => 'انناس', 'Pistachio' => 'پستہ', 'Coffee' => 'کافی', 'Mixed' => 'ملا جلا',
        'Serves' => 'کتنے افراد کے لیے', 'Eggless' => 'انڈے کے بغیر', 'Best before' => 'استعمال کی آخری تاریخ',

        // Books & stationery
        'Fiction' => 'فکشن', 'Novels and short stories.' => 'ناول اور افسانے۔', 'Non-fiction' => 'غیر افسانوی', 'History, biography, self-help and more.' => 'تاریخ، سوانح، خود سازی اور بہت کچھ۔',
        'Religious & Islamic' => 'مذہبی اور اسلامی', 'Quran, tafseer, hadith and Islamic books.' => 'قرآن، تفسیر، حدیث اور اسلامی کتب۔', "Children's books" => 'بچوں کی کتابیں',
        'Story books, activity books and early learning.' => 'کہانیوں کی کتابیں، سرگرمیوں کی کتابیں اور ابتدائی تعلیم۔', 'School & exam books' => 'اسکول اور امتحان کی کتابیں',
        'Textbooks, guides and exam preparation.' => 'نصابی کتابیں، گائیڈز اور امتحان کی تیاری۔', 'Stationery' => 'اسٹیشنری', 'Notebooks, pens and art supplies.' => 'کاپیاں، قلم اور آرٹ کا سامان۔',
        'Notebooks & paper' => 'کاپیاں اور کاغذ', 'Pens & pencils' => 'قلم اور پنسلیں', 'Art supplies' => 'آرٹ کا سامان',
        'Author' => 'مصنف', 'Publisher' => 'ناشر', 'Language' => 'زبان', 'Urdu' => 'اردو', 'English' => 'انگریزی', 'Arabic' => 'عربی', 'Bilingual' => 'دو زبانی',
        'Format' => 'جلد', 'Paperback' => 'پیپر بیک', 'Hardcover' => 'مجلد', 'Pages' => 'صفحات', 'Reader age' => 'قاری کی عمر', 'Children' => 'بچے', 'Teens' => 'نوجوان', 'Adults' => 'بالغ',

        // Kids, toys & baby
        'Toys & games' => 'کھلونے اور کھیل', 'Toys, puzzles and games by age.' => 'عمر کے حساب سے کھلونے، پزل اور کھیل۔', 'Baby care' => 'بچوں کی دیکھ بھال',
        'Diapers, wipes, bath and skin care for babies.' => 'بچوں کے لیے ڈائپر، وائپس، نہلانے اور جلد کی دیکھ بھال کا سامان۔', 'Kids clothing' => 'بچوں کے کپڑے',
        'Feeding & nursery' => 'فیڈنگ اور نرسری', 'Bottles, feeding sets and nursery items.' => 'فیڈر، کھلانے کے سیٹ اور نرسری کا سامان۔', 'School supplies' => 'اسکول کا سامان',
        'Bags, lunch boxes and stationery.' => 'بستے، لنچ باکس اور اسٹیشنری۔', 'Age' => 'عمر', 'Silicone' => 'سلیکون', 'Needs batteries' => 'بیٹری درکار',
        '0–6 months' => '0–6 ماہ', '6–12 months' => '6–12 ماہ', '1–2 years' => '1–2 سال', '3–5 years' => '3–5 سال', '6–8 years' => '6–8 سال', '9–12 years' => '9–12 سال',

        // Sports & fitness
        'Cricket' => 'کرکٹ', 'Bats, balls, pads and kits.' => 'بلے، گیندیں، پیڈ اور کٹس۔', 'Football' => 'فٹ بال', 'Footballs, boots and kits.' => 'فٹ بال، بوٹ اور کٹس۔',
        'Gym & fitness' => 'جم اور فٹنس', 'Dumbbells, mats, bands and machines.' => 'ڈمبل، میٹ، بینڈ اور مشینیں۔', 'Racket sports' => 'ریکٹ والے کھیل', 'Badminton, tennis and table tennis.' => 'بیڈمنٹن، ٹینس اور ٹیبل ٹینس۔',
        'Sportswear' => 'کھیلوں کے کپڑے', 'Shirts, trousers and tracksuits for sport.' => 'کھیل کے لیے شرٹس، ٹراؤزر اور ٹریک سوٹ۔', 'Outdoor & cycling' => 'آؤٹ ڈور اور سائیکلنگ',
        'Bicycles, helmets and outdoor gear.' => 'سائیکلیں، ہیلمٹ اور آؤٹ ڈور سامان۔', 'Sport' => 'کھیل', 'Hockey' => 'ہاکی', 'Badminton' => 'بیڈمنٹن', 'Tennis' => 'ٹینس',
        'Running' => 'دوڑ', 'Cycling' => 'سائیکلنگ', 'Willow' => 'بید کی لکڑی', 'Aluminium' => 'ایلومینیم', 'Carbon fibre' => 'کاربن فائبر',

        // Handicrafts & art
        'Hand-made pieces for the home.' => 'گھر کے لیے ہاتھ سے بنی چیزیں۔', 'Textiles & shawls' => 'کپڑے اور شالیں', 'Embroidered and woven textiles.' => 'کڑھائی والے اور بُنے ہوئے کپڑے۔',
        'Pottery & ceramics' => 'مٹی کے برتن اور سرامک', 'Hand-thrown and painted pottery.' => 'ہاتھ سے بنے اور رنگے ہوئے مٹی کے برتن۔', 'Woodwork' => 'لکڑی کا کام', 'Carved and inlaid wood.' => 'نقش و نگار اور جڑاؤ والی لکڑی۔',
        'Art & paintings' => 'فن اور پینٹنگز', 'Paintings, calligraphy and prints.' => 'پینٹنگز، خطاطی اور پرنٹس۔', 'Gifts' => 'تحائف', 'Hand-made gifts.' => 'ہاتھ سے بنے تحائف۔',
        'Craft' => 'دستکاری', 'Hand embroidery' => 'ہاتھ کی کڑھائی', 'Block print' => 'بلاک پرنٹ', 'Pottery' => 'کوزہ گری', 'Wood carving' => 'لکڑی پر نقاشی', 'Truck art' => 'ٹرک آرٹ',
        'Marble & onyx' => 'سنگِ مرمر اور سنگِ سلیمانی', 'Weaving' => 'بُنائی', 'Clay' => 'مٹی', 'Onyx' => 'سنگِ سلیمانی', 'Camel skin' => 'اونٹ کی کھال', 'Handmade' => 'ہاتھ سے بنا', 'Made in' => 'کہاں بنا',

        // General store
        'Home & living' => 'گھر اور رہن سہن', 'Things for the home.' => 'گھر کی چیزیں۔', 'Personal care' => 'ذاتی نگہداشت', 'Everyday personal care.' => 'روزمرہ ذاتی نگہداشت۔',
        'Bags, wallets and small accessories.' => 'بیگ، بٹوے اور چھوٹے لوازمات۔', 'Gifts for every occasion.' => 'ہر موقع کے تحائف۔', 'Small' => 'چھوٹا', 'Medium' => 'درمیانہ', 'Large' => 'بڑا',
    ];

    /** The Urdu for this English text, from the template's own glossary first, then this one. */
    public static function for(string $english, array $own = []): ?string
    {
        $text = $own[$english] ?? self::TEXT[$english] ?? null;

        return is_string($text) && $text !== '' ? $text : null;
    }
}
