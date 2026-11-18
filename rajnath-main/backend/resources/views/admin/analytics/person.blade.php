@php
    $C = \App\Http\Controllers\Admin\AnalyticsController::class;
    $time = fn (int $s) => $s >= 3600 ? intdiv($s, 3600).'h '.intdiv($s % 3600, 60).'m' : ($s >= 60 ? intdiv($s, 60).'m '.($s % 60).'s' : $s.'s');
    $ist = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t, 'UTC')->tz('Asia/Kolkata')->format('d M Y, g:i A') : '–';
    $labels = ['pageview' => 'Opened page', 'click' => 'Clicked', 'engage' => 'Read the page', 'tool_run' => 'Used a free tool', 'pdf_download' => 'Downloaded PDF',
        'app_open' => 'Opened the app', 'app_screen' => 'App screen', 'app_tap' => 'App tap', 'app_call' => 'App used'];
@endphp
<x-layouts.admin :title="'Activity: '.$u->name">
    <p><a href="{{ route('admin.customers.show', $u->ref) }}">← Customer</a> · <a href="{{ route('admin.analytics.visits', ['signed_in' => 1]) }}">Visitors & visits</a></p>
    <p class="mt-2 max-w-3xl text-sm text-ink-600">Everything {{ $u->name }} did on the website and in the app, including visits on the same browser before signing in. Opening this page is recorded in the audit log.</p>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ([
            ['First seen', $ist($summary->first_seen)], ['Last seen', $ist($summary->last_seen)], ['Website visits', number_format((int) $summary->visits)],
            ['Pages opened', number_format((int) $summary->pages)], ['Time on the website', $time((int) $summary->secs)], ['Clicks', number_format((int) $summary->clicks)],
            ['Free tools used', number_format((int) $summary->tools)], ['PDFs downloaded', number_format((int) $summary->pdfs)], ['App opens', number_format((int) $summary->app_opens)],
            ['App screens', number_format((int) $summary->app_screens)],
        ] as [$l, $val])
            <div class="card p-4"><p class="text-xs text-ink-600">{{ $l }}</p><p class="mt-1 text-lg font-semibold tabular-nums">{{ $val }}</p></div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <div class="card p-5"><h2 class="text-base">Devices</h2><ul class="mt-2 space-y-1 text-sm">@forelse ($devices as $d)<li>{{ $d->k }} <span class="text-ink-600">· last {{ $ist($d->last) }}</span></li>@empty<li class="text-ink-600">None yet.</li>@endforelse</ul>
            <h2 class="mt-4 text-base">Countries</h2><ul class="mt-2 space-y-1 text-sm">@foreach ($countries as $c)<li>{{ $C::countryName($c->k) }} ({{ $c->n }})</li>@endforeach</ul></div>
        <div class="card p-5"><h2 class="text-base">Most opened pages</h2><ul class="mt-2 space-y-1 text-sm">@forelse ($topPages as $p)<li class="break-all">{{ $p->k }} <span class="text-ink-600">({{ $p->n }})</span></li>@empty<li class="text-ink-600">None yet.</li>@endforelse</ul></div>
        <div class="card p-5"><h2 class="text-base">Free tools</h2><ul class="mt-2 space-y-1 text-sm">@forelse ($runs as $run)<li>@can('tools.history')<a href="{{ route('admin.analytics.tools.show', $run->ref) }}">{{ \App\Domain\Analytics\ToolHistory::name($run->tool) }}</a>@else{{ \App\Domain\Analytics\ToolHistory::name($run->tool) }}@endcan <span class="text-ink-600">· {{ $ist($run->created_at) }} · {{ $run->channel }}@if ($run->pdf_downloads) · {{ $run->pdf_downloads }} PDF @endif</span></li>@empty<li class="text-ink-600">None yet.</li>@endforelse</ul></div>
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-2">
        <div class="card p-5"><h2 class="text-base">Bookings and calls</h2>
            <ul class="mt-2 space-y-1 text-sm">@forelse ($bookings as $b)<li>{{ $ist($b->start_utc) }} · {{ str_replace('_', ' ', $b->status) }} · {{ \App\Models\OfferingVariant::MODES[$b->mode] ?? $b->mode }}@if ($b->meeting_provider === 'livekit') · <a href="{{ route('admin.ops.booking.calls', $b->ref) }}">call log</a>@endif</li>@empty<li class="text-ink-600">No bookings.</li>@endforelse</ul>
            @if ($calls->isNotEmpty())<p class="mt-3 text-xs text-ink-600">Latest call events: @foreach ($calls->take(8) as $c){{ $c->event }}@if ($c->detail) ({{ $c->detail }})@endif · @endforeach</p>@endif
        </div>
        <div class="card p-5"><h2 class="text-base">Orders and puja requests</h2>
            <ul class="mt-2 space-y-1 text-sm">@foreach ($orders as $o)<li>{{ $ist($o->paid_at ?? $o->created_at) }} · {{ $o->kind }} · {{ str_replace('_', ' ', $o->status) }} · {{ \App\Support\Money::format((int) $o->payable_minor) }}</li>@endforeach
                @foreach ($pujas as $p)<li>{{ $ist($p->created_at) }} · Puja request: {{ $p->puja_type }} · {{ \App\Models\PujaRequest::STATUSES[$p->status] ?? $p->status }}</li>@endforeach
                @if ($orders->isEmpty() && $pujas->isEmpty())<li class="text-ink-600">None.</li>@endif</ul>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-end justify-between gap-3">
        <h2 class="text-base">Timeline</h2>
        <form method="GET" class="flex items-end gap-2"><div><label for="p-t" class="field-label">Show</label><select id="p-t" name="type" class="input min-h-10"><option value="">Everything</option>@foreach ($labels as $k => $l)<option value="{{ $k }}" @selected($type === $k)>{{ $l }}</option>@endforeach</select></div><button class="btn-outline min-h-10">Show</button></form>
    </div>
    <div class="table-wrap mt-3" role="region" aria-label="Timeline" tabindex="0"><table class="table">
        <thead><tr><th scope="col">When (IST)</th><th scope="col">Where</th><th scope="col">What</th><th scope="col">Details</th><th scope="col"></th></tr></thead>
        <tbody>@forelse ($events as $e)<tr>
            <td class="whitespace-nowrap">{{ $ist($e->occurred_at) }}</td>
            <td class="text-sm">{{ $e->channel === 'app' ? 'App '.$e->app_version : 'Website' }}<br><span class="text-xs text-ink-600">{{ ucfirst((string) $e->device) }} · {{ $e->os }}</span></td>
            <td>{{ $labels[$e->type] ?? $e->type }}</td>
            <td class="max-w-md break-all text-sm">@if ($e->type === 'click'){{ $e->label }} @if ($e->target)→ {{ $e->target }}@endif
                @elseif ($e->type === 'engage'){{ $time((int) $e->value) }} @if ($e->depth !== null)· scrolled {{ $e->depth }}%@endif · {{ $e->path }}
                @elseif (in_array($e->type, ['tool_run', 'app_screen', 'app_tap'], true)){{ $e->type === 'tool_run' ? \App\Domain\Analytics\ToolHistory::name((string) $e->label) : $e->label }}
                @elseif ($e->type === 'app_call'){{ \App\Domain\Analytics\Reports::feature($e->route) }}
                @else{{ $e->path }}@endif</td>
            <td>@if ($e->session && $e->channel === 'web')<a href="{{ route('admin.analytics.visit', $e->session) }}" class="text-sm">visit</a>@endif</td>
        </tr>@empty<tr><td colspan="5" class="py-8 text-center text-ink-600">No activity recorded.</td></tr>@endforelse</tbody>
    </table></div>
    <div class="mt-4">{{ $events->links() }}</div>
</x-layouts.admin>
