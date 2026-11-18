@props(['place' => 'content'])
@php
    $google = app(\App\Support\Google::class);
    $unit = $google->adsHere(request()) ? $google->slot($place) : null;
@endphp
@if ($unit)
    {{-- Ad on a free tool page: labelled, space kept while loading (no jumping), loaded when scrolled near (resources/js/app.js). --}}
    <aside {{ $attributes->merge(['class' => 'arj-ad print:hidden']) }} aria-label="{{ __('Advertisement') }}" data-no-track>
        <p class="mb-1 text-center text-[0.65rem] uppercase tracking-widest text-ink-400">{{ __('Advertisement') }}</p>
        <div class="overflow-hidden rounded-xl bg-ivory-100/60" style="min-height: {{ $place === 'side' ? '250px' : '100px' }}">
            <ins class="adsbygoogle" style="display:block" data-ad-client="{{ $google->adsClient() }}" data-ad-slot="{{ $unit }}" data-ad-format="{{ $place === 'side' ? 'rectangle' : 'auto' }}" data-full-width-responsive="true"></ins>
        </div>
    </aside>
@endif
