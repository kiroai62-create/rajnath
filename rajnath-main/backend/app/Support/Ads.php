<?php

namespace App\Support;

use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

/**
 * Ads on the free tool pages (Admin › Google & ads). Several places on a page, each with its own network:
 * Google AdSense, Google Ad Manager, Media.net, Adsterra, or any network's own code ("Custom code", for example
 * Ezoic, Amazon, PropellerAds, Taboola). Off until the owner switches ads on; never on other pages, never for
 * signed-in staff, never in the app.
 */
class Ads
{
    /** Ad networks. "fields" are what each place needs. */
    public const NETWORKS = [
        'adsense' => ['label' => 'Google AdSense', 'fields' => ['slot']],
        'gam' => ['label' => 'Google Ad Manager', 'fields' => ['slot', 'size']],
        'medianet' => ['label' => 'Media.net', 'fields' => ['slot', 'size']],
        'adsterra' => ['label' => 'Adsterra (banner)', 'fields' => ['slot', 'size']],
        'custom' => ['label' => 'Custom code (any network)', 'fields' => ['code']],
    ];

    /** Networks usually added with "Custom code": shown as a hint list in the admin. */
    public const OTHER_NETWORKS = ['Ezoic', 'Amazon Publisher Services', 'PropellerAds', 'Taboola', 'Outbrain', 'Monumetric', 'Mediavine', 'Raptive (AdThrive)', 'PubMatic', 'InMobi', 'Infolinks', 'Revcontent'];

    /** Places on a free tool page. */
    public const PLACES = [
        'top' => 'Below the page title (calculators)',
        'side' => 'Beside the form (large screens only)',
        'result' => 'Below the result (calculators)',
        'bottom' => 'End of the page (every chosen page)',
    ];

    /** Free tool pages that may show ads, by group. */
    public const AD_PAGES = [
        'tools' => ['label' => 'Free calculators (Kundli, matching, numerology and the others)', 'routes' => ['tools.index', 'tools.show', 'tools.compute']],
        'panchang' => ['label' => 'Panchang, muhurat, festivals and vrat calendar', 'routes' => ['panchang', 'muhurat.index', 'muhurat.show', 'festivals', 'calendar']],
        'rashifal' => ['label' => 'Rashifal, sign pages, grah gochar and the nine planets', 'routes' => ['rashifal', 'rashifal.kind', 'rashi', 'gochar', 'gochar.planet', 'navagraha']],
    ];

    public function __construct(private SettingsRepository $settings) {}

    public function on(): bool
    {
        return (bool) $this->settings->get('ads.enabled', false) && $this->units() !== [];
    }

    public function adsenseClient(): ?string
    {
        $c = trim((string) $this->settings->get('ads.client'));

        return preg_match('/^ca-pub-\d{10,20}$/', $c) ? $c : null;
    }

    public function gamNetwork(): ?string
    {
        $n = trim((string) $this->settings->get('ads.gam_network'));

        return preg_match('/^\d{3,15}$/', $n) ? $n : null;
    }

    public function medianetCid(): ?string
    {
        $c = trim((string) $this->settings->get('ads.medianet_cid'));

        return preg_match('/^[A-Z0-9]{6,15}$/i', $c) ? $c : null;
    }

    /**
     * Places that are switched on and complete.
     *
     * @return array<string, array{network: string, slot: ?string, size: ?string, code: ?string, mobile: bool, desktop: bool}>
     */
    public function units(): array
    {
        $saved = $this->settings->get('ads.units');
        if (! is_array($saved)) {
            // Before several places existed: one AdSense unit below the result (and beside the form).
            $slot = $this->settings->get('ads.slot_content');
            $saved = $slot ? ['result' => ['on' => true, 'network' => 'adsense', 'slot' => $slot], 'side' => ['on' => true, 'network' => 'adsense', 'slot' => $this->settings->get('ads.slot_side') ?: $slot]] : [];
        }
        $out = [];
        foreach (self::PLACES as $place => $_) {
            $u = $saved[$place] ?? null;
            if (! is_array($u) || empty($u['on'])) {
                continue;
            }
            $u = ['network' => (string) ($u['network'] ?? ''), 'slot' => $u['slot'] ?? null, 'size' => $u['size'] ?? null, 'code' => $u['code'] ?? null,
                'mobile' => (bool) ($u['mobile'] ?? true), 'desktop' => (bool) ($u['desktop'] ?? true)];
            if ($this->complete($u)) {
                $out[$place] = $u;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $u */
    private function complete(array $u): bool
    {
        return match ($u['network']) {
            'adsense' => $this->adsenseClient() && preg_match('/^\d{6,14}$/', (string) $u['slot']),
            'gam' => $this->gamNetwork() && $u['slot'] && $u['size'],
            'medianet' => $this->medianetCid() && $u['slot'] && $u['size'],
            'adsterra' => $u['slot'] && $u['size'],
            'custom' => trim((string) $u['code']) !== '',
            default => false,
        };
    }

    /** @return list<string> chosen page groups (all by default) */
    public function groups(): array
    {
        $g = $this->settings->get('ads.pages');

        return is_array($g) ? array_values(array_intersect(array_keys(self::AD_PAGES), $g)) : array_keys(self::AD_PAGES);
    }

    /** True when this page shows ads. */
    public function here(Request $request): bool
    {
        if (! $this->on() || $request->is('admin', 'admin/*', 'staff/*', 'account/*', 'api/*', 'call/*') || $request->user()?->isStaff()) {
            return false;
        }
        $routes = collect($this->groups())->flatMap(fn ($g) => self::AD_PAGES[$g]['routes'])->all();

        return $routes !== [] && $request->routeIs(...$routes);
    }

    /** The ad for a place on this page, or null. */
    public function unit(Request $request, string $place): ?array
    {
        return $this->here($request) ? ($this->units()[$place] ?? null) : null;
    }

    /** @return list<string> networks used on this page */
    public function networksHere(Request $request): array
    {
        return $this->here($request) ? array_values(array_unique(array_column($this->units(), 'network'))) : [];
    }

    /** Owner's extra code for the page head on ad pages (some networks need a header script). */
    public function headCode(Request $request): string
    {
        return $this->here($request) ? self::withNonce((string) $this->settings->get('ads.head_code', '')) : '';
    }

    /** Content of /ads.txt, or null when there is nothing to declare. */
    public function adsTxt(): ?string
    {
        $lines = [];
        if ($c = $this->adsenseClient()) {
            $lines[] = 'google.com, '.substr($c, 3).', DIRECT, f08c47fec0942fa0';
        }
        foreach (preg_split('/\R/', (string) $this->settings->get('ads.txt_extra', '')) as $l) {
            $l = trim(strip_tags($l));
            if ($l !== '' && ! in_array($l, $lines, true)) {
                $lines[] = $l;
            }
        }

        return $lines ? implode("\n", $lines)."\n" : null;
    }

    /** "300x250, 728x90" → [[300, 250], [728, 90]] */
    public static function sizes(?string $sizes): array
    {
        preg_match_all('/(\d{2,4})\s*x\s*(\d{2,4})/i', (string) $sizes, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => [(int) $x[1], (int) $x[2]], $m);
    }

    /**
     * Gives every <script> in owner-entered code this page's CSP nonce, so it may run (and load what it needs,
     * 'strict-dynamic'). Only staff with settings.manage can enter this code.
     */
    public static function withNonce(string $html): string
    {
        $nonce = Vite::cspNonce();

        return $html === '' ? '' : preg_replace('/<script\b(?![^>]*\bnonce=)/i', '<script nonce="'.$nonce.'"', $html);
    }
}
