@php
    $C = \App\Http\Controllers\Admin\AnalyticsController::class;
    $labels = ['pageview' => 'Opened page', 'click' => 'Clicked', 'engage' => 'Read the page', 'tool_run' => 'Used a free tool', 'pdf_download' => 'Downloaded PDF'];
    $time = fn (int $s) => $s >= 60 ? intdiv($s, 60).'m '.($s % 60).'s' : $s.'s';
@endphp
<x-layouts.admin title="One visit">
    <p><a href="{{ route('admin.analytics.visits') }}">← Visitors & visits</a></p>
    <div class="card mt-3 grid gap-3 p-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
        <p><span class="block text-xs text-ink-600">Who</span>@if ($person)<a href="{{ route('admin.analytics.person', $person->id) }}">{{ $person->name }}</a>@else Visitor {{ substr($first->visitor, 0, 6) }} (not signed in)@endif</p>
        <p><span class="block text-xs text-ink-600">Came from</span>{{ $first->referrer ?: 'Direct / typed the address' }}@if ($first->utm_campaign) · {{ $first->utm_source }} / {{ $first->utm_campaign }}@endif</p>
        <p><span class="block text-xs text-ink-600">Device</span>{{ ucfirst((string) $first->device) }} · {{ $first->os }} · {{ $first->browser }} · {{ $C::countryName($first->country) }} · {{ strtoupper((string) $first->locale) }}</p>
        <p><span class="block text-xs text-ink-600">This browser</span>{{ $otherVisits }} {{ $otherVisits === 1 ? 'visit' : 'visits' }} in total · <a href="{{ route('admin.analytics.visits', ['visitor' => $first->visitor, 'days' => 365]) }}">all visits</a></p>
    </div>
    <ol class="mt-5 space-y-2">
        @foreach ($events as $e)
            <li class="card flex flex-wrap items-baseline gap-x-4 gap-y-1 p-3 text-sm">
                <span class="w-24 shrink-0 tabular-nums text-ink-600">{{ \Illuminate\Support\Carbon::parse($e->occurred_at, 'UTC')->tz('Asia/Kolkata')->format('g:i:s A') }}</span>
                <span @class(['font-semibold', 'text-gold-700' => $e->type === 'click'])>{{ $labels[$e->type] ?? $e->type }}</span>
                <span class="min-w-0 flex-1 break-all">
                    @if ($e->type === 'click'){{ $e->label }} @if ($e->target)<span class="text-ink-600">→ {{ $e->target }}</span>@endif
                    @elseif ($e->type === 'engage'){{ $time((int) $e->value) }} on screen @if ($e->depth !== null) · scrolled {{ $e->depth }}%@endif <span class="text-ink-600">{{ $e->path }}</span>
                    @elseif ($e->type === 'tool_run'){{ \App\Domain\Analytics\ToolHistory::name((string) $e->label) }}
                    @else{{ $e->path }}@endif
                </span>
            </li>
        @endforeach
    </ol>
    @if ($runs->isNotEmpty())
        <h2 class="mt-6 text-base">Free tools used in this visit</h2>
        <ul class="mt-2 space-y-1 text-sm">@foreach ($runs as $run)<li>@can('tools.history')<a href="{{ route('admin.analytics.tools.show', $run->ref) }}">{{ \App\Domain\Analytics\ToolHistory::name($run->tool) }}</a>@else{{ \App\Domain\Analytics\ToolHistory::name($run->tool) }}@endcan — {{ $run->summary }} @if ($run->pdf_downloads) · {{ $run->pdf_downloads }} PDF @endif</li>@endforeach</ul>
    @endif
</x-layouts.admin>
