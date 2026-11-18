@props(['place' => 'bottom'])
@php
    $ads = app(\App\Support\Ads::class);
    $u = $ads->unit(request(), $place);
    $sizes = $u ? \App\Support\Ads::sizes($u['size']) : [];
    $minH = $place === 'side' ? 250 : ($sizes ? min(array_column($sizes, 1)) : 100);
    $show = $u ? match (true) { $u['mobile'] && $u['desktop'] => '', $u['mobile'] => 'lg:hidden', default => 'hidden lg:block' } : '';
@endphp
@if ($u)
    {{-- Ad on a free tool page: labelled, space kept while loading (no jumping), Google and Media.net units load when scrolled near (resources/js/app.js). --}}
    <aside {{ $attributes->merge(['class' => 'arj-ad print:hidden '.$show]) }} aria-label="{{ __('Advertisement') }}" data-no-track data-ad-place="{{ $place }}">
        <p class="mb-1 text-center text-[0.65rem] uppercase tracking-widest text-ink-400">{{ __('Advertisement') }}</p>
        <div class="flex justify-center overflow-hidden rounded-xl bg-ivory-100/60" style="min-height: {{ $minH }}px">
            @switch($u['network'])
                @case('adsense')
                    <ins class="adsbygoogle block w-full" style="display:block" data-ad-client="{{ $ads->adsenseClient() }}" data-ad-slot="{{ $u['slot'] }}" data-ad-format="{{ $place === 'side' ? 'rectangle' : 'auto' }}" data-full-width-responsive="true"></ins>
                    @break
                @case('gam')
                    <div id="gpt-{{ $place }}" class="arj-gpt" data-gpt-unit="/{{ $ads->gamNetwork() }}/{{ ltrim($u['slot'], '/') }}" data-gpt-sizes="{{ json_encode($sizes) }}"></div>
                    @break
                @case('medianet')
                    <div id="{{ $u['slot'] }}" class="arj-mnet" data-mnet-size="{{ $sizes ? $sizes[0][0].'x'.$sizes[0][1] : '300x250' }}"></div>
                    @break
                @case('adsterra')
                    @php [$w, $h] = $sizes[0] ?? [300, 250]; @endphp
                    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">atOptions = {'key': @json($u['slot']), 'format': 'iframe', 'height': {{ $h }}, 'width': {{ $w }}, 'params': {}};</script>
                    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" src="https://www.highperformanceformat.com/{{ rawurlencode($u['slot']) }}/invoke.js"></script>
                    @break
                @default
                    <div class="w-full">{!! \App\Support\Ads::withNonce((string) $u['code']) !!}</div>
            @endswitch
        </div>
    </aside>
@endif
