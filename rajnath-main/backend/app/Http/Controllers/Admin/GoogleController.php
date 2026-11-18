<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Ads;
use App\Support\Google;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Admin › Google & ads: Google Analytics, Search Console, and ads on the free tool pages (several networks). */
class GoogleController extends Controller
{
    public function edit(SettingsRepository $settings, Ads $ads): View
    {
        $this->authorize('settings.view');
        $units = $settings->get('ads.units');
        if (! is_array($units)) {
            $units = collect($ads->units())->map(fn ($u) => $u + ['on' => true])->all();
        }

        return view('admin.google.edit', [
            'v' => $settings->all(), 'units' => $units, 'chosen' => $ads->groups(), 'adsReady' => $ads->on(), 'adsTxt' => $ads->adsTxt(),
            'live' => array_keys($ads->units()),
        ]);
    }

    public function update(Request $request, SettingsRepository $settings): RedirectResponse
    {
        $this->authorize('settings.manage');
        $request->merge([
            'seo_google_verification' => Google::verificationCode($request->input('seo_google_verification')),
            'ads_client' => Google::adsClientId($request->input('ads_client')),
            'google_ga_id' => strtoupper(trim((string) $request->input('google_ga_id'))) ?: null,
        ]);
        $rules = [
            'google_ga_id' => ['nullable', 'regex:/^G-[A-Z0-9]{4,16}$/'],
            'google_analytics_url' => ['nullable', 'url:https', 'max:300', 'regex:#^https://analytics\.google\.com/#'],
            'seo_google_verification' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'google_search_console_url' => ['nullable', 'url:https', 'max:300', 'regex:#^https://search\.google\.com/#'],
            'ads_enabled' => ['nullable', 'boolean'],
            'ads_client' => ['nullable', 'regex:/^ca-pub-\d{10,20}$/'],
            'ads_gam_network' => ['nullable', 'regex:/^\d{3,15}$/'],
            'ads_medianet_cid' => ['nullable', 'regex:/^[A-Za-z0-9]{6,15}$/'],
            'ads_head_code' => ['nullable', 'string', 'max:20000'],
            'ads_txt_extra' => ['nullable', 'string', 'max:10000'],
            'ads_pages' => ['nullable', 'array'],
            'ads_pages.*' => [Rule::in(array_keys(Ads::AD_PAGES))],
            'units' => ['nullable', 'array'],
        ];
        foreach (array_keys(Ads::PLACES) as $p) {
            $rules += [
                "units.$p.on" => ['nullable', 'boolean'],
                "units.$p.network" => ['nullable', Rule::in(array_keys(Ads::NETWORKS))],
                "units.$p.slot" => ['nullable', 'string', 'max:200', 'regex:#^[A-Za-z0-9_\-./]+$#'],
                "units.$p.size" => ['nullable', 'string', 'max:100', 'regex:/^\s*\d{2,4}\s*x\s*\d{2,4}(\s*,\s*\d{2,4}\s*x\s*\d{2,4})*\s*$/i'],
                "units.$p.code" => ['nullable', 'string', 'max:20000'],
                "units.$p.mobile" => ['nullable', 'boolean'],
                "units.$p.desktop" => ['nullable', 'boolean'],
            ];
        }
        $d = $request->validate($rules, [
            'google_ga_id.regex' => 'The Measurement ID looks like G-ABC123XYZ (Google Analytics › Admin › Data streams).',
            'seo_google_verification.regex' => 'Paste the code from Search Console, or the whole meta tag it shows.',
            'ads_client.regex' => 'The publisher ID looks like pub-1234567890123456 (AdSense › Account › Account information).',
            'ads_gam_network.regex' => 'The Ad Manager network code is a number (Ad Manager › Admin › Global settings).',
            'ads_medianet_cid.regex' => 'The Media.net customer ID looks like 8CU1A2B3C (Media.net dashboard).',
            'units.*.slot.regex' => 'Use only letters, numbers, dots, slashes, dashes and underscores.',
            'units.*.size.regex' => 'Write sizes like 300x250 or 728x90, 300x250.',
            'google_analytics_url.regex' => 'Paste a link that starts with https://analytics.google.com/',
            'google_search_console_url.regex' => 'Paste a link that starts with https://search.google.com/',
        ]);

        $units = [];
        $errors = [];
        foreach (array_keys(Ads::PLACES) as $p) {
            $u = $d['units'][$p] ?? [];
            $net = $u['network'] ?? 'adsense';
            $unit = ['on' => (bool) ($u['on'] ?? false), 'network' => $net, 'slot' => isset($u['slot']) ? trim($u['slot']) : null,
                'size' => isset($u['size']) ? preg_replace('/\s+/', '', strtolower($u['size'])) : null, 'code' => $u['code'] ?? null,
                'mobile' => (bool) ($u['mobile'] ?? false), 'desktop' => (bool) ($u['desktop'] ?? false)];
            if ($unit['on']) {
                $need = Ads::NETWORKS[$net]['fields'];
                if (in_array('slot', $need, true) && ! $unit['slot']) {
                    $errors["units.$p.slot"] = match ($net) {
                        'adsense' => 'Add the AdSense ad unit ID (the number in data-ad-slot).', 'gam' => 'Add the Ad Manager ad unit code, for example astro/tools_result.',
                        'medianet' => 'Add the Media.net ad unit ID (crid).', default => 'Add the Adsterra banner key.',
                    };
                }
                if ($net === 'adsense' && $unit['slot'] && ! preg_match('/^\d{6,14}$/', $unit['slot'])) {
                    $errors["units.$p.slot"] = 'An AdSense ad unit ID is a number, for example 1234567890.';
                }
                if (in_array('size', $need, true) && ! $unit['size']) {
                    $errors["units.$p.size"] = 'Add the ad size, for example 300x250.';
                }
                if ($net === 'custom' && trim((string) $unit['code']) === '') {
                    $errors["units.$p.code"] = 'Paste the ad code from your ad network.';
                }
                if (! $unit['mobile'] && ! $unit['desktop']) {
                    $errors["units.$p.mobile"] = 'Choose phones, computers or both.';
                }
                $account = ['adsense' => ['ads_client', 'Add your AdSense publisher ID above.'], 'gam' => ['ads_gam_network', 'Add your Ad Manager network code above.'],
                    'medianet' => ['ads_medianet_cid', 'Add your Media.net customer ID above.']][$net] ?? null;
                if ($account && empty($d[$account[0]])) {
                    $errors[$account[0]] = $account[1];
                }
            }
            $units[$p] = $unit;
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (! empty($d['ads_enabled']) && ! collect($units)->contains('on', true)) {
            throw ValidationException::withMessages(['ads_enabled' => 'Switch on at least one ad place below.']);
        }

        $values = [
            'google.ga_id' => $d['google_ga_id'] ?? null,
            'google.analytics_url' => $d['google_analytics_url'] ?? null,
            'seo.google_verification' => $d['seo_google_verification'] ?? null,
            'google.search_console_url' => $d['google_search_console_url'] ?? null,
            'ads.enabled' => (bool) ($d['ads_enabled'] ?? false),
            'ads.client' => $d['ads_client'] ?? null,
            'ads.gam_network' => $d['ads_gam_network'] ?? null,
            'ads.medianet_cid' => isset($d['ads_medianet_cid']) ? strtoupper($d['ads_medianet_cid']) : null,
            'ads.head_code' => $d['ads_head_code'] ?? null,
            'ads.txt_extra' => $d['ads_txt_extra'] ?? null,
            'ads.units' => $units,
            'ads.pages' => array_values($d['ads_pages'] ?? []),
        ];
        foreach ($values as $key => $value) {
            if ($settings->get($key) !== $value) {
                $settings->set($key, $value);
            }
        }

        return back()->with('status', $values['ads.enabled'] ? 'Saved. Ads now show on the chosen free tool pages.' : 'Saved. Ads are off.');
    }
}
