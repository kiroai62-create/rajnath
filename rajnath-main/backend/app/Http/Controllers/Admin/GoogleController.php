<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Google;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Admin › Google & ads: Google Analytics, Search Console and AdSense on the free tool pages. */
class GoogleController extends Controller
{
    public function edit(SettingsRepository $settings, Google $google): View
    {
        $this->authorize('settings.view');

        return view('admin.google.edit', [
            'v' => $settings->all(),
            'groups' => Google::AD_PAGES,
            'chosen' => $google->adGroups(),
            'adsReady' => $google->adsOn(),
            'adsTxt' => $google->adsTxt(),
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
        $d = $request->validate([
            'google_ga_id' => ['nullable', 'regex:/^G-[A-Z0-9]{4,16}$/'],
            'google_analytics_url' => ['nullable', 'url:https', 'max:300', 'regex:#^https://analytics\.google\.com/#'],
            'seo_google_verification' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'google_search_console_url' => ['nullable', 'url:https', 'max:300', 'regex:#^https://search\.google\.com/#'],
            'ads_enabled' => ['nullable', 'boolean'],
            'ads_client' => ['nullable', 'required_if_accepted:ads_enabled', 'regex:/^ca-pub-\d{10,20}$/'],
            'ads_slot_content' => ['nullable', 'required_if_accepted:ads_enabled', 'regex:/^\d{6,14}$/'],
            'ads_slot_side' => ['nullable', 'regex:/^\d{6,14}$/'],
            'ads_pages' => ['nullable', 'array'],
            'ads_pages.*' => [Rule::in(array_keys(Google::AD_PAGES))],
        ], [
            'google_ga_id.regex' => 'The Measurement ID looks like G-ABC123XYZ (Google Analytics › Admin › Data streams).',
            'seo_google_verification.regex' => 'Paste the code from Search Console, or the whole meta tag it shows.',
            'ads_client.regex' => 'The publisher ID looks like pub-1234567890123456 (AdSense › Account › Account information).',
            'ads_client.required_if_accepted' => 'Add your AdSense publisher ID before switching ads on.',
            'ads_slot_content.required_if_accepted' => 'Add an ad unit ID before switching ads on (AdSense › Ads › By ad unit › Display ads).',
            'ads_slot_content.regex' => 'An ad unit ID is the number in data-ad-slot, for example 1234567890.',
            'ads_slot_side.regex' => 'An ad unit ID is the number in data-ad-slot, for example 1234567890.',
            'google_analytics_url.regex' => 'Paste a link that starts with https://analytics.google.com/',
            'google_search_console_url.regex' => 'Paste a link that starts with https://search.google.com/',
        ]);
        $values = [
            'google.ga_id' => $d['google_ga_id'] ?? null,
            'google.analytics_url' => $d['google_analytics_url'] ?? null,
            'seo.google_verification' => $d['seo_google_verification'] ?? null,
            'google.search_console_url' => $d['google_search_console_url'] ?? null,
            'ads.enabled' => (bool) ($d['ads_enabled'] ?? false),
            'ads.client' => $d['ads_client'] ?? null,
            'ads.slot_content' => $d['ads_slot_content'] ?? null,
            'ads.slot_side' => $d['ads_slot_side'] ?? null,
            'ads.pages' => array_values($d['ads_pages'] ?? []),
        ];
        foreach ($values as $key => $value) {
            if ($settings->get($key) !== $value) {
                $settings->set($key, $value);
            }
        }

        return back()->with('status', $values['ads.enabled'] ? 'Saved. Ads now show on the chosen free tool pages.' : 'Saved.'.(($d['ads_client'] ?? null) ? ' Ads are off.' : ''));
    }
}
