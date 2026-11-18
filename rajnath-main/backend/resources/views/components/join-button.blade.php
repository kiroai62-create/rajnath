@props(['b', 'size' => 'normal'])
@php
    // The booking's current call link: the in-app call page, or the latest Zoom / Google Meet / manual link saved
    // on the booking (so a changed link is always the one opened).
    $inApp = $b->meeting_provider === 'livekit';
    $url = $inApp ? route('call.join', $b->ref) : ($b->meeting_link ?: null);
    $start = \Illuminate\Support\Carbon::parse($b->start_utc, 'UTC');
    $end = \Illuminate\Support\Carbon::parse($b->end_utc ?? $b->start_utc, 'UTC');
    $early = now()->lt($start->copy()->subMinutes(\App\Domain\Meetings\LiveKit::EARLY_MINUTES));
    $live = ! $early && now()->lte($end->copy()->addMinutes(\App\Domain\Meetings\LiveKit::LATE_MINUTES));
    $online = in_array($b->mode, \App\Domain\Meetings\Meetings::ONLINE_MODES, true);
@endphp
@if ($online)
    @if ($url)
        <a href="{{ $url }}"{!! $inApp ? '' : ' target="_blank" rel="noopener"' !!}
           {{ $attributes->merge(['class' => ($live ? 'btn-primary' : 'btn-outline').' '.($size === 'small' ? 'min-h-9 px-3 py-1.5 text-sm' : 'px-5')]) }}
           data-track="Click to join">
            <span aria-hidden="true">●</span> {{ $live ? 'Click to join' : 'Join link' }}@unless ($inApp) ({{ \App\Domain\Meetings\Meetings::PROVIDERS[$b->meeting_provider] ?? 'link' }})@endunless
        </a>
        @if ($early)<span class="text-xs text-ink-600">Opens 15 min before the start</span>@endif
    @else
        <span class="text-xs text-ink-600">No call link yet — create one below.</span>
    @endif
@endif
