<!DOCTYPE html>
{{-- Phase B55 (Module 25 §72): the page the store's app shows offline. Kept by the service worker; every value is escaped. --}}
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="{{ $theme }}">
    <title>{{ $urdu ? 'آپ آف لائن ہیں' : 'You are offline' }} · {{ $name }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: {{ $background }}; color: #1f2937; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 26rem; padding: 2rem 1.25rem; text-align: center; }
        img { width: 72px; height: 72px; border-radius: 16px; }
        h1 { font-size: 1.4rem; margin: 1rem 0 .5rem; }
        p { line-height: 1.6; color: #4b5563; }
        a { display: inline-block; margin-top: 1rem; padding: .75rem 1.5rem; border-radius: .5rem; background: {{ $theme }}; color: #fff; text-decoration: none; font-weight: 600; min-width: 8rem; }
    </style>
</head>
<body>
<main>
    @if ($icon)
        <img src="{{ $icon }}" alt="">
    @endif
    <p><strong>{{ $name }}</strong></p>
    @if ($urdu)
        <h1>آپ آف لائن ہیں</h1>
        <p>انٹرنیٹ سے رابطہ بحال ہوتے ہی یہ صفحہ کھل جائے گا۔ آرڈر دینے کے لیے انٹرنیٹ ضروری ہے، تاکہ قیمت اور اسٹاک درست رہیں۔</p>
        <a href="{{ $home }}">دوبارہ کوشش کریں</a>
    @else
        <h1>You are offline</h1>
        <p>This page opens as soon as you are back online. Ordering needs a connection, so prices and stock are always up to date.</p>
        <a href="{{ $home }}">Try again</a>
    @endif
</main>
<script>window.addEventListener('online', function () { window.location.reload(); });</script>
</body>
</html>
