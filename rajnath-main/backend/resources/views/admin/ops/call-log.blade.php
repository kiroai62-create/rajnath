@php
    $labels = [
        'joined' => 'Joined', 'rejoined' => 'Rejoined by itself after a drop', 'reconnecting' => 'Connection weak, reconnecting', 'reconnected' => 'Reconnected',
        'dropped' => 'Connection dropped', 'left' => 'Left the call', 'ended' => 'Call ended', 'camera_error' => 'Camera did not start',
        'mic_error' => 'Microphone did not start', 'failed' => 'Could not connect', 'background' => 'App went to the background', 'foreground' => 'App back on screen',
    ];
    $bad = ['dropped', 'camera_error', 'mic_error', 'failed'];
@endphp
<x-layouts.admin title="Call log">
    <p><a href="{{ route('admin.ops.bookings') }}">← Bookings</a></p>
    <h2 class="mt-3 text-base">{{ $customer?->name }} · {{ \Illuminate\Support\Carbon::parse($b->start_utc, 'UTC')->tz('Asia/Kolkata')->format('D d M Y, g:i A') }} IST · {{ \App\Models\OfferingVariant::MODES[$b->mode] ?? $b->mode }}</h2>
    <p class="mt-1 max-w-3xl text-sm text-ink-600">What the website and the app reported during this in-app call. "Rejoined by itself" means the internet dropped and the call came back without anyone pressing a button.
        "Camera did not start" shows the reason the browser or phone gave (for example NotAllowedError = permission blocked, NotReadableError = camera used by another app, NotFoundError = no camera).</p>
    <div class="table-wrap mt-4" role="region" aria-label="Call log" tabindex="0"><table class="table">
        <thead><tr><th scope="col">Time (IST)</th><th scope="col">Who</th><th scope="col">What happened</th><th scope="col">Details</th><th scope="col">Device</th></tr></thead>
        <tbody>@forelse ($rows as $r)<tr>
            <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($r->created_at, 'UTC')->tz('Asia/Kolkata')->format('d M, g:i:s A') }}</td>
            <td>{{ $r->name }} <span class="text-xs text-ink-600">({{ $r->role }}, {{ $r->channel === 'app' ? 'app' : 'website' }})</span></td>
            <td @class(['font-semibold text-danger-700' => in_array($r->event, $bad, true)])>{{ $labels[$r->event] ?? $r->event }}</td>
            <td class="text-sm">{{ $r->detail }}</td>
            <td class="max-w-xs truncate text-xs text-ink-600" title="{{ $r->device }}">{{ $r->device }}</td>
        </tr>@empty<tr><td colspan="5" class="py-8 text-center text-ink-600">Nobody has joined this call yet.</td></tr>@endforelse</tbody>
    </table></div>
</x-layouts.admin>
