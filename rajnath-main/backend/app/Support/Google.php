<?php

namespace App\Support;

use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Request;

/**
 * Google Analytics and Search Console (Admin › Google & ads), and the security policy for pages with Google tags
 * or ads (see Ads). Until a visitor accepts cookies, Google gets "consent denied" (Consent Mode v2): Analytics
 * counts without cookies and Google ads are not personalised.
 */
class Google
{
    public function __construct(private SettingsRepository $settings) {}

    public function analyticsId(): ?string
    {
        $id = strtoupper(trim((string) $this->settings->get('google.ga_id')));

        return preg_match('/^G-[A-Z0-9]{4,16}$/', $id) ? $id : null;
    }

    /** True when this page shows ads (see Ads). */
    public function adsHere(Request $request): bool
    {
        return app(Ads::class)->here($request);
    }

    public function adsClient(): ?string
    {
        return app(Ads::class)->adsenseClient();
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
            // Ad networks load scripts, pictures and frames from many changing hosts: on ad pages scripts started by
            // our own nonce'd tags are trusted ('strict-dynamic'), and pictures, frames and requests may use any https host.
            $out['strict'] = true;
            $out['connect'] .= ' https:';
            $out['img'] .= ' https:';
            $out['frame'] .= ' https:';
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
