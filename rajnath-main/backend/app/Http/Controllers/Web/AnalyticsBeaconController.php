<?php

namespace App\Http\Controllers\Web;

use App\Domain\Analytics\Tracker;
use App\Http\Controllers\Controller;
use App\Support\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Receives page views, clicks, time on page and scroll depth from resources/js/app.js (navigator.sendBeacon).
 * A page view counts only when the page's one-time token comes back from a real browser (see TrackVisit).
 */
class AnalyticsBeaconController extends Controller
{
    public function __invoke(Request $request, Tracker $tracker): Response
    {
        if (! $tracker->enabled() || $tracker->ignored($request)) {
            return response()->noContent();
        }
        $data = $request->validate([
            'type' => ['required', 'in:pageview,click,engage'],
            'pv' => ['nullable', 'string', 'size:32', 'alpha_num'],
            'depth' => ['nullable', 'integer', 'min:0', 'max:100'],
            'path' => ['required', 'string', 'max:300'],
            'label' => ['nullable', 'string', 'max:200'],
            'target' => ['nullable', 'string', 'max:300'],
            'seconds' => ['nullable', 'integer', 'min:0', 'max:3600'],
        ]);
        $ids = $request->attributes->get('analytics.ids') ?? $tracker->identify($request);
        if (! Tracker::validId((string) $request->cookie(Tracker::VISITOR_COOKIE))) {
            return response()->noContent();   // no page view was counted for this browser
        }
        if ($data['type'] === 'pageview') {
            // One-time: the token is taken out of the cache, so a page view is counted once even if sent twice.
            $view = ! empty($data['pv']) ? Cache::pull('an:pv:'.$data['pv']) : null;
            if (is_array($view) && ($view['visitor'] ?? null) === $request->cookie(Tracker::VISITOR_COOKIE)) {
                $tracker->record('pageview', $view);
                Tracker::countFiltered('confirmed');
                if (! Cache::has('an:confirmed_since')) {
                    $settings = app(SettingsRepository::class);
                    if (! $settings->get('analytics.confirmed_since')) {
                        // Start of browser-confirmed counting (arj:analytics-clean-bots cleans only before this).
                        $settings->set('analytics.confirmed_since', now()->toIso8601String(), false, 'analytics');
                    }
                    Cache::forever('an:confirmed_since', 1);
                }
            }

            return response()->noContent();
        }
        $path = Tracker::cleanPath((string) parse_url($data['path'], PHP_URL_PATH));
        $tracker->record($data['type'], $tracker->context($request, $ids) + [
            'path' => mb_substr($path, 0, 300),
            'label' => isset($data['label']) ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($data['label']))), 0, 200) : null,
            'target' => self::target($data['target'] ?? null),
            'value' => $data['type'] === 'engage' ? (int) ($data['seconds'] ?? 0) : null,
            'depth' => $data['type'] === 'engage' && isset($data['depth']) ? (int) $data['depth'] : null,
        ]);

        return response()->noContent();
    }

    /** Link without its query string or fragment (those can hold personal data or tokens). */
    private static function target(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $p = parse_url($url);
        if ($p === false) {
            return null;
        }
        if (isset($p['scheme']) && in_array($p['scheme'], ['tel', 'mailto'], true)) {
            return $p['scheme'].':';
        }
        $own = ! isset($p['host']) || $p['host'] === parse_url((string) config('app.url'), PHP_URL_HOST) || $p['host'] === request()->getHost();

        return mb_substr(($own ? '' : $p['host']).Tracker::cleanPath($p['path'] ?? '/'), 0, 300);
    }
}
