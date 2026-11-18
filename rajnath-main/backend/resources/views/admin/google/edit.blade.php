@php
    $A = \App\Support\Ads::class;
    $hint = ['adsense' => 'AdSense › Ads › By ad unit › Display ads (Responsive). The number in data-ad-slot.', 'gam' => 'Ad unit code from Ad Manager › Inventory › Ad units, for example astro/tools_result. Sizes such as 728x90, 300x250.',
        'medianet' => 'Ad unit ID (crid) and size from the Media.net dashboard.', 'adsterra' => 'Banner key (the long code in the Adsterra banner) and its size, for example 300x250.',
        'custom' => 'Paste the ad code your network gives you. Scripts are allowed.'];
@endphp
<x-layouts.admin title="Google & ads">
    <form method="POST" action="{{ route('admin.google.update') }}" class="max-w-4xl space-y-6">
        @csrf @method('PUT')
        @if ($errors->any())<div class="card border-danger-700/30 bg-red-50 p-4 text-sm text-danger-700" role="alert">Please check the fields marked below. {{ $errors->first() }}</div>@endif

        <section class="card space-y-4 p-5 sm:p-6" aria-labelledby="ga-h">
            <h2 id="ga-h" class="text-lg">Google Analytics</h2>
            <p class="text-sm text-ink-600">Adds Google Analytics 4 to the website (not to the admin). Our own <a href="{{ route('admin.analytics.website') }}">Website visitors</a> report keeps working as well.
                Find the ID in Google Analytics › Admin › Data streams › your website: it starts with <strong>G-</strong>.</p>
            <div class="grid gap-4 md:grid-cols-2">
                <x-field name="google_ga_id" label="Measurement ID" :value="$v['google.ga_id'] ?? ''" placeholder="G-ABC123XYZ" />
                <x-field name="google_analytics_url" label="Link to your Google Analytics report (optional)" :value="$v['google.analytics_url'] ?? ''" placeholder="https://analytics.google.com/…" hint="Shown as a button in Admin › Website visitors." />
            </div>
        </section>

        <section class="card space-y-4 p-5 sm:p-6" aria-labelledby="sc-h">
            <h2 id="sc-h" class="text-lg">Google Search Console</h2>
            <p class="text-sm text-ink-600">In Search Console choose <em>URL prefix</em> › <em>HTML tag</em>, copy the tag and paste it here (the whole tag or only the code). Save, then press <em>Verify</em> in Search Console.
                Also submit the sitemap there: <code class="break-all">{{ url('/sitemap.xml') }}</code></p>
            <div class="grid gap-4 md:grid-cols-2">
                <x-field name="seo_google_verification" label="Verification code or meta tag" :value="$v['seo.google_verification'] ?? ''" placeholder="Paste the meta tag or the code" :hint="($v['seo.google_verification'] ?? null) ? 'Saved. The tag is on every page of the website.' : null" />
                <x-field name="google_search_console_url" label="Link to your Search Console property (optional)" :value="$v['google.search_console_url'] ?? ''" placeholder="https://search.google.com/…" hint="Shown as a button in Admin › Website visitors." />
            </div>
        </section>

        <section class="card space-y-5 p-5 sm:p-6" aria-labelledby="ads-h">
            <div>
                <h2 id="ads-h" class="text-lg">Ads on the free tool pages</h2>
                <p class="mt-1 text-sm text-ink-600">Choose up to four places on each page and the ad network for each: Google AdSense, Google Ad Manager, Media.net, Adsterra, or <strong>Custom code</strong> for any other network
                    ({{ implode(', ', $A::OTHER_NETWORKS) }}…). Ads are labelled "Advertisement", keep their space while loading, Google and Media.net ads load only when scrolled near, and every ad fits the screen.
                    Staff signed in to the admin never see ads. <strong>The app shows no ads.</strong></p>
            </div>
            <label class="flex min-h-11 items-center gap-3 rounded-xl border border-ink-200 p-3"><input type="checkbox" name="ads_enabled" value="1" @checked(old('ads_enabled', $v['ads.enabled'] ?? false)) class="size-5 accent-ink-900">
                <span><strong>Show ads</strong> <span class="text-sm text-ink-600">— off means no ads anywhere and no ad code on the site.</span></span></label>
            @error('ads_enabled')<p class="field-error">{{ $message }}</p>@enderror
            <p class="rounded-xl bg-ivory-100 p-3 text-sm"><strong>Now:</strong> {{ $adsReady ? 'Ads are on: '.implode(', ', array_map(fn ($p) => $A::PLACES[$p], $live)).'.' : 'Ads are off.' }}</p>

            <fieldset class="space-y-3"><legend class="text-base font-semibold">Ad network accounts</legend>
                <p class="text-sm text-ink-600">Only fill in the networks you use.</p>
                <div class="grid gap-4 md:grid-cols-3">
                    <x-field name="ads_client" label="AdSense publisher ID" :value="$v['ads.client'] ?? ''" placeholder="pub-1234567890123456" />
                    <x-field name="ads_gam_network" label="Google Ad Manager network code" :value="$v['ads.gam_network'] ?? ''" placeholder="21234567890" />
                    <x-field name="ads_medianet_cid" label="Media.net customer ID" :value="$v['ads.medianet_cid'] ?? ''" placeholder="8CU1A2B3C" />
                </div>
            </fieldset>

            <fieldset class="space-y-3"><legend class="text-base font-semibold">Ad places</legend>
                @foreach ($A::PLACES as $p => $label)
                    @php $u = old('units.'.$p, $units[$p] ?? []); $net = $u['network'] ?? 'adsense'; @endphp
                    <div class="rounded-2xl border border-ink-200 p-4" x-data="adUnit" data-network="{{ $net }}">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="units[{{ $p }}][on]" value="1" @checked($u['on'] ?? false) class="size-5 accent-ink-900"> {{ $label }}</label>
                            <div class="flex flex-wrap gap-4 text-sm">
                                <label class="flex items-center gap-1.5"><input type="checkbox" name="units[{{ $p }}][mobile]" value="1" @checked($u['mobile'] ?? $p !== 'side') class="size-4 accent-ink-900"> Phones</label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" name="units[{{ $p }}][desktop]" value="1" @checked($u['desktop'] ?? true) class="size-4 accent-ink-900"> Computers &amp; tablets</label>
                            </div>
                        </div>
                        @error('units.'.$p.'.mobile')<p class="field-error">{{ $message }}</p>@enderror
                        <div class="mt-3 grid gap-4 md:grid-cols-[14rem_1fr_10rem]">
                            <div class="min-w-0"><label class="field-label" for="u-{{ $p }}-net">Network</label>
                                <select id="u-{{ $p }}-net" name="units[{{ $p }}][network]" class="input" @change="pick">@foreach ($A::NETWORKS as $k => $n)<option value="{{ $k }}" @selected($net === $k)>{{ $n['label'] }}</option>@endforeach</select></div>
                            <div class="min-w-0" x-show="needsSlot"><x-field :name="'units['.$p.'][slot]'" :id="'u-'.$p.'-slot'" label="Ad unit ID / code / key" :value="$u['slot'] ?? ''" /></div>
                            <div class="min-w-0" x-show="needsSize"><x-field :name="'units['.$p.'][size]'" :id="'u-'.$p.'-size'" label="Size" :value="$u['size'] ?? ''" placeholder="300x250" /></div>
                        </div>
                        @foreach (['slot', 'size', 'code'] as $f)@error('units.'.$p.'.'.$f)<p class="field-error">{{ $message }}</p>@enderror @endforeach
                        <div class="mt-3" x-show="isCustom" x-cloak><label class="field-label" for="u-{{ $p }}-code">Ad code</label>
                            <textarea id="u-{{ $p }}-code" name="units[{{ $p }}][code]" rows="4" class="input font-mono text-xs" data-no-ai spellcheck="false" placeholder="Paste the ad code here">{{ $u['code'] ?? '' }}</textarea></div>
                        @foreach ($hint as $k => $h)<p class="mt-2 text-xs text-ink-600" x-show="is{{ ucfirst($k) }}" @if ($k !== $net) x-cloak @endif>{{ $h }}</p>@endforeach
                    </div>
                @endforeach
            </fieldset>

            <fieldset><legend class="field-label">Pages with ads</legend>
                <div class="space-y-2">@foreach ($A::AD_PAGES as $key => $g)
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="ads_pages[]" value="{{ $key }}" @checked(in_array($key, old('ads_pages', $chosen), true)) class="mt-0.5 size-4 accent-ink-900"> {{ $g['label'] }}</label>
                @endforeach</div>
            </fieldset>

            <details class="rounded-2xl border border-ink-200 p-4" @if (($v['ads.head_code'] ?? '') || ($v['ads.txt_extra'] ?? '')) open @endif>
                <summary class="cursor-pointer font-semibold">Advanced: header code and ads.txt</summary>
                <div class="mt-3 space-y-4">
                    <div><label class="field-label" for="f-head">Header code for ad pages (optional)</label>
                        <textarea id="f-head" name="ads_head_code" rows="4" class="input font-mono text-xs" data-no-ai spellcheck="false" placeholder="For networks such as Ezoic or Amazon that give a script for the page head">{{ old('ads_head_code', $v['ads.head_code'] ?? '') }}</textarea>
                        <p class="field-hint">Added only to the pages with ads, not to the rest of the website.</p></div>
                    <div><label class="field-label" for="f-txt">Extra ads.txt lines (optional)</label>
                        <textarea id="f-txt" name="ads_txt_extra" rows="4" class="input font-mono text-xs" data-no-ai spellcheck="false" placeholder="media.net, 8CU1A2B3C, DIRECT">{{ old('ads_txt_extra', $v['ads.txt_extra'] ?? '') }}</textarea>
                        <p class="field-hint">One line per network, as the network gives it. The AdSense line is added for you.@if ($adsTxt) Live file: <a href="{{ url('/ads.txt') }}" target="_blank" rel="noopener">{{ url('/ads.txt') }}</a>@endif</p></div>
                </div>
            </details>
            <p class="text-xs text-ink-600">Visitors see a small cookie choice. Until they accept, Google gets "consent denied" and shows ads that are not personalised. Other networks follow their own rules; check their consent options.</p>
        </section>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-xs text-ink-600">Saving requires a fresh password + authenticator confirmation. Changes are audited.</p>
            @can('settings.manage')<button class="btn-primary">Save</button>@endcan
        </div>
    </form>
</x-layouts.admin>
