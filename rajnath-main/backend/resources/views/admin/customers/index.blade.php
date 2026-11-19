<x-layouts.admin title="Customers">
    {{-- Sign-ups: how many joined on the website and in the app. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['All customers', $sum['total'], null, null], ['Joined on the website', $sum['web'], 'web', null], ['Joined in the app', $sum['app'], 'app', null],
            ['Use the app now', $sum['app_now'], null, 'signed in on a phone'], ['Joined today', $sum['today'], null, null], ['Last 7 days', $sum['week'], null, null],
            ['Last 30 days', $sum['month'], null, null], ['Signed in last 7 days', $sum['active7'], null, 'active customers'],
        ] as [$label, $n, $filter, $hint])
            <a @if ($filter) href="{{ route('admin.customers.index', ['channel' => $filter]) }}" @endif class="card block p-4 no-underline">
                <span class="block text-xs text-ink-600">{{ $label }}</span>
                <span class="mt-1 block text-2xl font-semibold tabular-nums text-ink-900">{{ number_format($n) }}</span>
                @if ($hint)<span class="block text-xs text-ink-600">{{ $hint }}</span>@endif
            </a>
        @endforeach
    </div>
    @php $max = max(1, collect($daily)->map(fn ($d) => $d[1] + $d[2])->max()); @endphp
    <section class="card mt-4 p-4" aria-labelledby="su-h">
        <div class="flex flex-wrap items-center justify-between gap-2"><h2 id="su-h" class="text-base">New customers per day (30 days)</h2>
            <p class="flex gap-3 text-xs"><span><span class="inline-block size-2.5 rounded-sm bg-ink-800"></span> Website</span><span><span class="inline-block size-2.5 rounded-sm bg-gold-500"></span> App</span></p></div>
        <div class="mt-3 flex h-28 items-end gap-[2px] overflow-hidden" role="img" aria-label="New customers per day, website and app">
            @foreach ($daily as [$d, $web, $app])
                <div class="flex min-w-0 flex-1 flex-col justify-end" title="{{ \Illuminate\Support\Carbon::parse($d)->format('d M') }}: {{ $web }} website, {{ $app }} app">
                    <div class="bg-gold-500" style="height: {{ round($app * 100 / $max) }}%"></div>
                    <div class="rounded-t-sm bg-ink-800" style="height: {{ round($web * 100 / $max) }}%"></div>
                </div>
            @endforeach
        </div>
    </section>

    <form method="GET" class="mt-5 flex flex-wrap items-end gap-2" role="search">
        <div class="min-w-0 flex-1 basis-60"><x-field name="q" label="Search name, email or mobile" :value="$q" /></div>
        <div><label for="f-ch" class="field-label">Joined on</label><select id="f-ch" name="channel" class="input"><option value="">Website and app</option>
            @foreach (['web' => 'Website', 'app' => 'App', 'both' => 'Website, now also uses the app'] as $k => $l)<option value="{{ $k }}" @selected($channel === $k)>{{ $l }}</option>@endforeach</select></div>
        <button class="btn-outline">Show</button>
    </form>
    <div class="table-wrap mt-4" role="region" aria-label="Customers" tabindex="0">
        <table class="table">
            <thead><tr><th scope="col">Name</th><th scope="col">Email / mobile</th><th scope="col">Joined</th><th scope="col">Uses</th><th scope="col">Last sign-in</th><th scope="col">Status</th></tr></thead>
            <tbody>
            @forelse ($list as $c)
                <tr>
                    <td><a href="{{ route('admin.customers.show', $c) }}" class="font-medium">{{ $c->name }}</a></td>
                    <td class="text-sm">{{ $c->email ? \App\Support\Logging\Redactor::mask($c->email) : '' }}@if ($c->email && $c->phone_e164)<br>@endif{{ $c->phone_e164 ? \App\Support\Logging\Redactor::mask($c->phone_e164) : '' }}</td>
                    <td class="whitespace-nowrap">{{ $c->created_at->tz('Asia/Kolkata')->format('d M Y') }}<br><span class="text-xs text-ink-600">on the {{ $c->signup_channel === 'app' ? 'app' : 'website' }}</span></td>
                    <td>@if ($c->signup_channel !== 'app')<span class="badge-ink">Website</span>@endif @if (isset($appUsers[$c->id]) || $c->signup_channel === 'app')<span class="badge-gold">App</span>@endif</td>
                    <td class="whitespace-nowrap text-sm">{{ $c->last_login_at?->tz('Asia/Kolkata')->format('d M Y, g:i A') ?? '—' }}</td>
                    <td>@if ($c->isActive())<span class="badge-success">Active</span>@else<span class="badge-danger">{{ ucfirst($c->status) }}</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-8 text-center text-ink-600">No customers found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $list->links() }}</div>
</x-layouts.admin>
