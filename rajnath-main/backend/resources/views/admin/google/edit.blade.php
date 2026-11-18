<x-layouts.admin title="Google & ads">
    <form method="POST" action="{{ route('admin.google.update') }}" class="max-w-3xl space-y-6">
        @csrf @method('PUT')

        <section class="card space-y-4 p-5 sm:p-6" aria-labelledby="ga-h">
            <h2 id="ga-h" class="text-lg">Google Analytics</h2>
            <p class="text-sm text-ink-600">Adds Google Analytics 4 to the website (not to the admin). Our own <a href="{{ route('admin.analytics.website') }}">Website visitors</a> report keeps working as well.
                Find the ID in Google Analytics › Admin › Data streams › your website: it starts with <strong>G-</strong>.</p>
            <x-field name="google_ga_id" label="Measurement ID" :value="$v['google.ga_id'] ?? ''" placeholder="G-ABC123XYZ" />
            <x-field name="google_analytics_url" label="Link to your Google Analytics report (optional)" :value="$v['google.analytics_url'] ?? ''" placeholder="https://analytics.google.com/analytics/web/#/p123456789/reports" hint="Shown as a button in Admin › Website visitors." />
        </section>

        <section class="card space-y-4 p-5 sm:p-6" aria-labelledby="sc-h">
            <h2 id="sc-h" class="text-lg">Google Search Console</h2>
            <p class="text-sm text-ink-600">In Search Console choose <em>URL prefix</em> › <em>HTML tag</em>, copy the tag and paste it here (the whole tag or only the code). Save, then press <em>Verify</em> in Search Console.
                Also submit the sitemap there: <code>{{ url('/sitemap.xml') }}</code></p>
            <x-field name="seo_google_verification" label="Verification code or meta tag" :value="$v['seo.google_verification'] ?? ''" placeholder='<meta name="google-site-verification" content="…">' />
            <x-field name="google_search_console_url" label="Link to your Search Console property (optional)" :value="$v['google.search_console_url'] ?? ''" placeholder="https://search.google.com/search-console?resource_id=…" hint="Shown as a button in Admin › Website visitors." />
        </section>

        <section class="card space-y-4 p-5 sm:p-6" aria-labelledby="ads-h">
            <h2 id="ads-h" class="text-lg">Ads on the free tool pages (Google AdSense)</h2>
            <p class="text-sm text-ink-600">Ads show only when the switch below is on, only on the pages you tick, and at most two per page: below the result and, on large screens, under the form.
                They are marked "Advertisement", keep their space while loading so the page does not jump, load only when scrolled near, and adapt to every screen size. Google chooses ads that match the page (astrology, puja, gemstones).
                The team does not see ads while signed in to the admin. <strong>The app shows no ads.</strong></p>
            <label class="flex min-h-10 items-center gap-3 rounded-xl border border-ink-200 p-3"><input type="checkbox" name="ads_enabled" value="1" @checked(old('ads_enabled', $v['ads.enabled'] ?? false)) class="size-5 accent-ink-900">
                <span><strong>Show ads</strong> <span class="text-sm text-ink-600">— off means no ads anywhere and no AdSense code on the site.</span></span></label>
            @error('ads_enabled')<p class="field-error">{{ $message }}</p>@enderror
            <x-field name="ads_client" label="AdSense publisher ID" :value="$v['ads.client'] ?? ''" placeholder="pub-1234567890123456" hint="AdSense › Account › Account information." />
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="ads_slot_content" label="Ad unit ID (below the result)" :value="$v['ads.slot_content'] ?? ''" placeholder="1234567890" hint="AdSense › Ads › By ad unit › Display ads › Responsive. The number in data-ad-slot." />
                <x-field name="ads_slot_side" label="Second ad unit ID (beside the form, optional)" :value="$v['ads.slot_side'] ?? ''" placeholder="Uses the first one if empty" />
            </div>
            <fieldset><legend class="field-label">Pages with ads</legend>
                <div class="space-y-2">@foreach ($groups as $key => $g)
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="ads_pages[]" value="{{ $key }}" @checked(in_array($key, old('ads_pages', $chosen), true)) class="mt-0.5 size-4 accent-ink-900"> {{ $g['label'] }}</label>
                @endforeach</div>
            </fieldset>
            <div class="rounded-xl bg-ivory-100 p-3 text-sm">
                <p><strong>Status:</strong> {{ $adsReady ? 'Ads are on.' : 'Ads are off.' }}</p>
                @if ($adsTxt)<p class="mt-1">Google checks <a href="{{ url('/ads.txt') }}" target="_blank" rel="noopener">{{ url('/ads.txt') }}</a>; it already contains your publisher line.</p>@endif
                <p class="mt-1 text-ink-600">Visitors see a small cookie choice at the bottom of the page. Until they accept, Google Analytics counts without cookies and ads are not personalised (Google Consent Mode).</p>
            </div>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-ink-600">Saving requires a fresh password + authenticator confirmation. Changes are audited.</p>
            @can('settings.manage')<button class="btn-primary">Save</button>@endcan
        </div>
    </form>
</x-layouts.admin>
