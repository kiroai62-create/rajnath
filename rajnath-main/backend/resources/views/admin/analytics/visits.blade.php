@php
    $C = \App\Http\Controllers\Admin\AnalyticsController::class;
    $time = fn (int $s) => $s >= 60 ? intdiv($s, 60).'m '.($s % 60).'s' : $s.'s';
@endphp
<x-layouts.admin title="Visitors & visits">
    @include('admin.analytics._range')
    <form method="GET" class="card mt-5 flex flex-wrap items-end gap-3 p-4">
        @foreach (request()->only('days', 'from', 'to') as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <label class="flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" name="signed_in" value="1" @checked($f['signed_in'] ?? false) class="size-4 accent-ink-900"> Signed-in customers only</label>
        <div><label for="v-dev" class="field-label">Device</label><select id="v-dev" name="device" class="input min-h-10"><option value="">All</option>@foreach (['mobile' => 'Mobile', 'tablet' => 'Tablet', 'desktop' => 'Desktop'] as $k => $l)<option value="{{ $k }}" @selected(($f['device'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div><label for="v-c" class="field-label">Country code</label><input id="v-c" name="country" value="{{ $f['country'] ?? '' }}" maxlength="2" placeholder="IN" class="input min-h-10 w-20 uppercase"></div>
        <div><label for="v-s" class="field-label">Came from</label><input id="v-s" name="source" value="{{ $f['source'] ?? '' }}" placeholder="google, instagram, direct" class="input min-h-10"></div>
        <button class="btn-outline min-h-10">Filter</button>
    </form>
    <p class="mt-3 max-w-3xl text-sm text-ink-600">Every visit by a real browser, newest first. Bots and crawlers are not counted. Open a visit to see each page in order, the clicks, time on screen and how far the page was scrolled. A name appears when the person was signed in; open it for everything that person did.</p>
    <div class="table-wrap mt-3" role="region" aria-label="Visits" tabindex="0"><table class="table">
        <thead><tr><th scope="col">Started (IST)</th><th scope="col">Who</th><th scope="col">From</th><th scope="col">Device</th><th scope="col">First page</th><th scope="col">Pages</th><th scope="col">Clicks</th><th scope="col">Time</th><th scope="col">Scrolled</th><th scope="col"></th></tr></thead>
        <tbody>@forelse ($rows as $v)<tr>
            <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($v->started, 'UTC')->tz('Asia/Kolkata')->format('d M, g:i A') }}</td>
            <td>@if ($v->name)<a href="{{ route('admin.analytics.person', $v->user_id) }}">{{ $v->name }}</a>@else<span class="text-ink-600">Visitor {{ substr($v->visitor, 0, 6) }}</span>@endif @if ($v->is_new)<span class="badge-gold ml-1">new</span>@endif</td>
            <td>{{ $v->referrer ?: 'Direct' }}@if ($v->campaign)<br><span class="text-xs text-ink-600">{{ $v->campaign }}</span>@endif<br><span class="text-xs text-ink-600">{{ $C::countryName($v->country) }}</span></td>
            <td class="text-sm">{{ ucfirst((string) $v->device) }}<br><span class="text-xs text-ink-600">{{ $v->os }} · {{ $v->browser }}</span></td>
            <td class="max-w-[14rem] truncate text-sm" title="{{ $v->entry }}">{{ $v->entry }}</td>
            <td class="tabular-nums">{{ $v->views }}</td><td class="tabular-nums">{{ $v->clicks }}</td><td class="tabular-nums">{{ $time((int) $v->secs) }}</td>
            <td class="tabular-nums">{{ $v->depth !== null ? $v->depth.'%' : '–' }}</td>
            <td><a href="{{ route('admin.analytics.visit', $v->session) }}">Open</a></td>
        </tr>@empty<tr><td colspan="10" class="py-8 text-center text-ink-600">No visits in this period.</td></tr>@endforelse</tbody>
    </table></div>
    <div class="mt-4">{{ $rows->links() }}</div>
</x-layouts.admin>
