@php
    // One readable line per event ("key: value · key: value"); the full details open on demand.
    $summary = function (?string $json): string {
        $d = $json ? json_decode($json, true) : null;
        if (! is_array($d)) {
            return (string) $json;
        }
        return collect($d)->map(fn ($v, $k) => str_replace('_', ' ', (string) $k).': '.(is_array($v) ? (array_is_list($v) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? $x : '…', $v)) : '…') : (is_bool($v) ? ($v ? 'yes' : 'no') : ($v ?? '—'))))->implode(' · ');
    };
@endphp
<x-layouts.admin title="Audit log">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <form method="GET" class="flex w-full max-w-md items-end gap-2" role="search">
            <div class="min-w-0 flex-1"><x-field name="action" label="Filter by action prefix" :value="$action" placeholder="e.g. staff." /></div>
            <button class="btn-outline">Filter</button>
        </form>
        <a href="{{ route('admin.audit.verify') }}" class="btn-outline">Verify integrity</a>
    </div>
    <ul class="card mt-5 divide-y divide-ink-200/60 overflow-hidden" aria-label="Audit events">
        @forelse ($logs as $l)
            @php $line = $summary($l->context); @endphp
            <li class="px-4 py-2.5 text-sm">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
                    <span class="w-10 shrink-0 tabular-nums text-xs text-ink-400">#{{ $l->id }}</span>
                    <code class="font-semibold text-ink-900">{{ $l->action }}</code>
                    <span class="text-ink-600">{{ $l->actor_label }}</span>
                    @if ($l->subject_type)<span class="text-xs text-ink-600">{{ class_basename($l->subject_type) }} {{ $l->subject_ref }}</span>@endif
                    <span class="ml-auto whitespace-nowrap text-xs tabular-nums text-ink-600">{{ \Illuminate\Support\Carbon::parse($l->created_at, 'UTC')->tz('Asia/Kolkata')->format('d M, H:i:s') }}</span>
                </div>
                @if ($line !== '')
                    <details class="group mt-0.5 pl-0 sm:pl-[3.25rem]">
                        <summary class="flex cursor-pointer list-none items-baseline gap-2 text-xs text-ink-600 [&::-webkit-details-marker]:hidden">
                            <span class="min-w-0 flex-1 truncate group-open:hidden">{{ $line }}</span>
                            <span class="shrink-0 font-semibold text-gold-700"><span class="group-open:hidden">More</span><span class="hidden group-open:inline">Less</span></span>
                        </summary>
                        <pre class="mt-1 max-h-60 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-ivory-100 p-2 text-[0.7rem] text-ink-700">{{ json_encode(json_decode($l->context, true) ?? $l->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                    </details>
                @endif
            </li>
        @empty
            <li class="py-8 text-center text-ink-600">No matching events.</li>
        @endforelse
    </ul>
    <div class="mt-4">{{ $logs->links() }}</div>
</x-layouts.admin>
