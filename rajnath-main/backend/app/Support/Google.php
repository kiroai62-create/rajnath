<?php

namespace App\Support;

use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Request;

/**
 * Google Analytics, Search Console and AdSense, set in Admin › Google & ads.
 *
 * Ads appear only when the owner switches them on, only on the free tool pages chosen there, never to signed-in
 * staff (so the team cannot click its own ads by mistake), and never in the app. Until a visitor accepts cookies,
 * Google gets "consent denied" (Consent Mode v2): Analytics counts without cookies and ads are not personalised.
 */
class Google
{
    /** Free tool pages that may show ads, by group (Admin › Google & ads). */
    public const AD_PAGES = [
        'tools' => ['label' => 'Free calculators (Kundli, matching, numerology and the others)', 'routes' => ['tools.index', 'tools.show', 'tools.compute']],
        'panchang' => ['label' => 'Panchang, muhurat, festivals and vrat calendar', 'routes' => ['panchang', 'muhurat.index', 'muhurat.show', 'festivals', 'calendar']],
        'rashifal' => ['label' => 'Rashifal, sign pages, grah gochar and the nine planets', 'routes' => ['rashifal', 'rashifal.kind', 'rashi', 'gochar', 'gochar.planet', 'navagraha']],
    ];

    public function __construct(private SettingsRepository $settings) {}

    public function analyticsId(): ?string
    {
        $id = strtoupper(trim((string) $this->settings->get('google.ga_id')));

        return preg_match('/^G-[A-Z0-9]{4,16}$/', $id) ? $id : null;
    }

    /** "ca-pub-1234567890123456" or null. */
    public function adsClient(): ?string
    {
        $c = trim((string) $this->settings->get('ads.client'));

        return preg_match('/^ca-pub-\d{10,20}$/', $c) ? $c : null;
    }

    public function adsOn(): bool
    {
        return (bool) $this->settings->get('ads.enabled', false) && $this->adsClient() && $this->slot('content');
    }

    /** AdSense display ad unit id for a place on the page ("content" or "side"; side falls back to content). */
    public function slot(string $place): ?string
    {
        $s = trim((string) ($this->settings->get('ads.slot_'.$place) ?: ($place === 'side' ? $this->settings->get('ads.slot_content') : '')));

        return preg_match('/^\d{6,14}$/', $s) ? $s : null;
    }

    /** @return list<string> chosen page groups (all by default) */
    public function adGroups(): array
    {
        $g = $this->settings->get('ads.pages');

        return is_array($g) ? array_values(array_intersect(array_keys(self::AD_PAGES), $g)) : array_keys(self::AD_PAGES);
    }

    /** True when this page shows ads. */
    public function adsHere(Request $request): bool
    {
        if (! $this->adsOn() || $request->is('admin', 'admin/*', 'staff/*', 'account/*', 'api/*') || $request->user()?->isStaff()) {
            return false;
        }
        $routes = collect($this->adGroups())->flatMap(fn ($g) => self::AD_PAGES[$g]['routes'])->all();

        return $routes !== [] && $request->routeIs(...$routes);
    }

    /** Google Analytics runs on public and customer pages, not in the control centre. */
    public function analyticsHere(Request $request): bool
    {
        return $this->analyticsId() !== null && ! $request->is('admin', 'admin/*', 'staff/*', 'call/*');
    }

    /** The cookie choice bar is needed when anything from Google runs on the page. */
    public function consentHere(Request $request): bool
    {
        return $this->analyticsHere($request) || $this->adsHere($request);
    }

    /** Content of /ads.txt, or null when AdSense is not set up. */
    public function adsTxt(): ?string
    {
        $c = $this->adsClient();

        return $c ? 'google.com, '.substr($c, 3).", DIRECT, f08c47fec0942fa0\n" : null;
    }

    /**
     * Extra Content-Security-Policy sources for this page.
     *
     * @return array{script: string, connect: string, img: string, frame: string, strict: bool}
     */
    public function csp(Request $request): array
    {
        $out = ['script' => '', 'connect' => '', 'img' => '', 'frame' => '', 'strict' => false];
        if ($this->analyticsHere($request)) {
            $out['script'] .= ' https://www.googletagmanager.com';
            $out['connect'] .= ' https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com';
            $out['img'] .= ' https://*.google-analytics.com https://*.googletagmanager.com';
        }
        if ($this->adsHere($request)) {
            // AdSense loads scripts from many Google hosts: allow scripts started by our nonce'd tags ('strict-dynamic').
            $out['strict'] = true;
            $out['connect'] .= ' https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com https://*.adtrafficquality.google https://*.googleadservices.com';
            $out['img'] .= ' https:';
            $out['frame'] .= ' https://*.googlesyndication.com https://*.doubleclick.net https://*.google.com https://*.adtrafficquality.google';
        }

        return $out;
    }

    /** Accepts the code, or the whole <meta name="google-site-verification" content="…"> tag pasted from Google. */
    public static function verificationCode(?string $input): ?string
    {
        $v = trim((string) $input);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $v, $m)) {
            $v = $m[1];
        }

        return $v === '' ? null : $v;
    }

    /** Accepts "pub-123…", "ca-pub-123…" or the ads.txt line. */
    public static function adsClientId(?string $input): ?string
    {
        return preg_match('/(?:ca-)?pub-(\d{10,20})/i', (string) $input, $m) ? 'ca-pub-'.$m[1] : (trim((string) $input) === '' ? null : trim((string) $input));
    }
}
