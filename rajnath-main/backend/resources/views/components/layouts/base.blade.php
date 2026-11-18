@props(['title' => null, 'description' => null, 'robots' => 'index,follow', 'image' => null, 'jsonld' => null, 'type' => 'website'])
@php
    $locale = app()->getLocale();
    $brand = $site('brand.name');
    $fullTitle = $title ? $title.' · '.$brand : $brand.' — '.__('Vedic astrologer in Patna');
    $indexable = str_starts_with($robots, 'index');
    $langs = \App\Http\Middleware\SetLocale::enabled();
    // Canonical: the clean path, plus ?lang for a non-English page; other query strings are dropped.
    $langUrl = fn (string $l) => $l === 'en' ? url()->current() : url()->current().'?lang='.$l;
    $ogLocale = ['en' => 'en_IN', 'hi' => 'hi_IN', 'bn' => 'bn_IN', 'gu' => 'gu_IN', 'kn' => 'kn_IN', 'ml' => 'ml_IN', 'mr' => 'mr_IN', 'pa' => 'pa_IN', 'ta' => 'ta_IN', 'te' => 'te_IN', 'ur' => 'ur_IN'];
    $img = asset($image ?? 'images/brand/og-default.jpg');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}"{!! \App\Http\Middleware\SetLocale::rtl($locale) ? ' dir="rtl"' : '' !!}>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#fdfbf6">
    <meta name="robots" content="{{ $indexable ? $robots.',max-image-preview:large,max-snippet:-1,max-video-preview:-1' : $robots }}">
    <title>{{ $fullTitle }}</title>
    @if ($description)<meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 300) }}">@endif
    <meta name="author" content="{{ $site('brand.practitioner_name') }}">
    @if ($indexable)
        <link rel="canonical" href="{{ $langUrl($locale) }}">
        @if (count($langs) > 1)
            @foreach ($langs as $l)<link rel="alternate" hreflang="{{ $l }}" href="{{ $langUrl($l) }}">@endforeach
            <link rel="alternate" hreflang="x-default" href="{{ url()->current() }}">
        @endif
    @endif
    <meta property="og:type" content="{{ $type }}">
    <meta property="og:site_name" content="{{ $brand }}">
    <meta property="og:title" content="{{ $title ?? $brand }}">
    @if ($description)<meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 300) }}">@endif
    <meta property="og:url" content="{{ $langUrl($locale) }}">
    <meta property="og:locale" content="{{ $ogLocale[$locale] ?? 'en_IN' }}">
    @foreach ($langs as $l)@if ($l !== $locale)<meta property="og:locale:alternate" content="{{ $ogLocale[$l] ?? $l }}">@endif @endforeach
    <meta property="og:image" content="{{ $img }}">
    <meta property="og:image:alt" content="{{ $title ?? $brand }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title ?? $brand }}">
    @if ($description)<meta name="twitter:description" content="{{ \Illuminate\Support\Str::limit(strip_tags($description), 200) }}">@endif
    <meta name="twitter:image" content="{{ $img }}">
    <meta name="geo.region" content="IN-BR">
    <meta name="geo.placename" content="Patna">
    @if ($v = $site('seo.google_verification'))<meta name="google-site-verification" content="{{ $v }}">@endif
    @if ($v = $site('seo.bing_verification'))<meta name="msvalidate.01" content="{{ $v }}">@endif
    <link rel="manifest" href="{{ route('manifest') }}">
    <link rel="icon" href="/favicon.ico" sizes="48x48">
    <link rel="icon" href="/images/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/icons/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="alternate" type="text/plain" title="LLM summary" href="{{ url('/llms.txt') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @if ($pv = request()->attributes->get('analytics.pv'))<meta name="arj-pv" content="{{ $pv }}">@endif
    @php
        $google = app(\App\Support\Google::class);
        $gaId = $google->analyticsHere(request()) ? $google->analyticsId() : null;
        $adNet = app(\App\Support\Ads::class);
        $networks = $adNet->networksHere(request());
        $adsClient = in_array('adsense', $networks, true) ? $adNet->adsenseClient() : null;
        $cspNonce = \Illuminate\Support\Facades\Vite::cspNonce();
    @endphp
    @if ($gaId || $networks)
        {{-- Google Consent Mode v2: nothing personal until the visitor accepts (cookie bar, resources/js/app.js). --}}
        <script nonce="{{ $cspNonce }}">window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('consent','default',{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500});
try{if(localStorage.getItem('arj_consent')==='all'){gtag('consent','update',{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'});}@if ($adsClient)else{(window.adsbygoogle=window.adsbygoogle||[]).requestNonPersonalizedAds=1;}@endif}catch(e){}
gtag('js',new Date());@if ($gaId)gtag('config',@json($gaId));@endif</script>
        @if ($gaId)<script async nonce="{{ $cspNonce }}" src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>@endif
        @if ($adsClient)<script async nonce="{{ $cspNonce }}" crossorigin="anonymous" src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client={{ $adsClient }}"></script>@endif
        @if (in_array('gam', $networks, true))<script async nonce="{{ $cspNonce }}" src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>@endif
        @if (in_array('medianet', $networks, true))<script nonce="{{ $cspNonce }}">window._mNHandle = window._mNHandle || {}; window._mNHandle.queue = window._mNHandle.queue || []; medianet_versionId = "3121199";</script>
        <script async nonce="{{ $cspNonce }}" src="https://contextual.media.net/dmedianet.js?cid={{ $adNet->medianetCid() }}"></script>@endif
        {!! $adNet->headCode(request()) !!}
    @endif
    @php
        $slugParam = (string) (request()->route('slug') ?? '');
        $arjPage = match (true) {
            request()->routeIs('store.show') => 'product:'.$slugParam,
            request()->routeIs('services.show') => 'service:'.$slugParam,
            request()->routeIs('blog.show') => 'article:'.$slugParam,
            request()->routeIs('tools.show') => 'tool:'.$slugParam,
            default => '/'.ltrim(request()->path(), '/'),
        };
    @endphp
    @if (request()->is('admin', 'admin/*') && auth()->user()?->isStaff())<meta name="arj-admin-ai" content="{{ route('admin.ai.assist', [], false) }}">@endif
    <meta name="arj-page" content="{{ \Illuminate\Support\Str::limit(strtolower($arjPage), 150, '') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @if ($indexable && $jsonld !== false){!! \App\Support\Seo\JsonLd::render(is_array($jsonld) ? $jsonld : []) !!}@endif
</head>
<body {{ $attributes->merge(['class' => 'min-h-dvh']) }}>
    <a href="#main" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 btn-primary">{{ __('Skip to content') }}</a>
    {{ $slot }}
    @if ($gaId || $networks)<x-consent-bar />@endif
</body>
</html>
