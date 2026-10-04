<!DOCTYPE html>
<html lang="{{ $page['props']['storefront']['store']['locale'] ?? 'en' }}" dir="{{ $page['props']['storefront']['language']['dir'] ?? 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php($seo = $page['props']['seo'] ?? null)
    @if (is_array($seo))
        {{-- Module 05 (Phase B24): storefront SEO written server-side, so
             crawlers see it without running JavaScript. The `inertia`
             attribute lets the client-side <Head> take the tags over on
             later page visits instead of duplicating them. Every value
             is escaped by Blade. --}}
        <title inertia>{{ $seo['title'] }}</title>
        @if (! empty($seo['description']))
            <meta name="description" content="{{ $seo['description'] }}" inertia="description">
        @endif
        <link rel="canonical" href="{{ $seo['canonical'] }}" inertia="canonical">
        {{-- Phase B38 (Module 16 §27): the same page in the store's other languages. --}}
        @foreach ($seo['alternates'] ?? [] as $alternate)
            <link rel="alternate" hreflang="{{ $alternate['hreflang'] }}" href="{{ $alternate['href'] }}">
        @endforeach
        <meta name="robots" content="{{ $seo['robots'] }}" inertia="robots">
        <meta property="og:type" content="website" inertia="og:type">
        @if (! empty($seo['og_locale']))
            <meta property="og:locale" content="{{ $seo['og_locale'] }}" inertia="og:locale">
        @endif
        <meta property="og:title" content="{{ $seo['og_title'] }}" inertia="og:title">
        <meta property="og:url" content="{{ $seo['canonical'] }}" inertia="og:url">
        @if (! empty($seo['og_description']))
            <meta property="og:description" content="{{ $seo['og_description'] }}" inertia="og:description">
        @endif
        @if (! empty($seo['og_image']))
            <meta property="og:image" content="{{ $seo['og_image'] }}" inertia="og:image">
        @endif
        @if (! empty($page['props']['storefront']['store']['favicon_url']))
            <link rel="icon" href="{{ $page['props']['storefront']['store']['favicon_url'] }}">
        @endif
        @foreach ($seo['structured_data'] ?? [] as $jsonLd)
            {{-- JSON_HEX_TAG/AMP: a "</script>" inside any value can never close this element. --}}
            <script type="application/ld+json">{!! json_encode($jsonLd, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endforeach
    @else
        <title inertia>Umar Techy E-Commerce Platform</title>
    @endif
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
