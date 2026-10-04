<?php

declare(strict_types=1);

/*
 * Phase B38 (SRS LOC-001): Urdu validation messages for the storefront —
 * the rules a shopper's forms use (account, address, cart, checkout,
 * returns, contact). A rule without an Urdu message here falls back to
 * Laravel's English one (app.fallback_locale = en).
 */
return [
    'accepted' => ':attribute کو قبول کرنا ضروری ہے۔',
    'array' => ':attribute ایک فہرست ہونی چاہیے۔',
    'boolean' => ':attribute ہاں یا نہیں ہونا چاہیے۔',
    'confirmed' => ':attribute کی تصدیق مماثل نہیں۔',
    'email' => ':attribute ایک درست ای میل پتہ ہونا چاہیے۔',
    'exists' => 'منتخب کردہ :attribute درست نہیں۔',
    'file' => ':attribute ایک فائل ہونی چاہیے۔',
    'image' => ':attribute ایک تصویر ہونی چاہیے۔',
    'in' => 'منتخب کردہ :attribute درست نہیں۔',
    'integer' => ':attribute پورا عدد ہونا چاہیے۔',
    'max' => [
        'array' => ':attribute میں :max سے زیادہ چیزیں نہیں ہو سکتیں۔',
        'file' => ':attribute :max کلوبائٹ سے بڑی نہیں ہو سکتی۔',
        'numeric' => ':attribute :max سے زیادہ نہیں ہو سکتا۔',
        'string' => ':attribute :max حروف سے زیادہ نہیں ہو سکتا۔',
    ],
    'mimes' => ':attribute ان اقسام میں سے ہونی چاہیے: :values۔',
    'min' => [
        'array' => ':attribute میں کم از کم :min چیزیں ہونی چاہییں۔',
        'file' => ':attribute کم از کم :min کلوبائٹ کی ہونی چاہیے۔',
        'numeric' => ':attribute کم از کم :min ہونا چاہیے۔',
        'string' => ':attribute کم از کم :min حروف کا ہونا چاہیے۔',
    ],
    'numeric' => ':attribute ایک عدد ہونا چاہیے۔',
    'present' => ':attribute موجود ہونا چاہیے۔',
    'regex' => ':attribute کی شکل درست نہیں۔',
    'required' => ':attribute ضروری ہے۔',
    'required_if' => ':attribute ضروری ہے۔',
    'required_with' => ':attribute ضروری ہے۔',
    'required_without' => ':attribute ضروری ہے۔',
    'required_without_all' => ':attribute ضروری ہے۔',
    'same' => ':attribute اور :other ایک جیسے ہونے چاہییں۔',
    'size' => [
        'string' => ':attribute :size حروف کا ہونا چاہیے۔',
    ],
    'string' => ':attribute متن ہونا چاہیے۔',
    'unique' => 'یہ :attribute پہلے سے استعمال میں ہے۔',
    'url' => ':attribute ایک درست ویب پتہ ہونا چاہیے۔',

    'attributes' => [
        'name' => 'نام',
        'email' => 'ای میل',
        'password' => 'پاس ورڈ',
        'password_confirmation' => 'پاس ورڈ کی تصدیق',
        'phone' => 'فون نمبر',
        'quantity' => 'تعداد',
        'message' => 'پیغام',
        'subject' => 'موضوع',
        'order_number' => 'آرڈر نمبر',
        'reason' => 'وجہ',
        'description' => 'تفصیل',
        'line1' => 'پتہ',
        'city' => 'شہر',
        'province' => 'صوبہ',
        'postal_code' => 'پوسٹل کوڈ',
        'country' => 'ملک',
        'shipping_address.line1' => 'پتہ',
        'shipping_address.city' => 'شہر',
        'shipping_address.country' => 'ملک',
        'payment_method' => 'ادائیگی کا طریقہ',
        'shipping_method_id' => 'ڈیلیوری کا طریقہ',
        'photo' => 'تصویر',
    ],
];
