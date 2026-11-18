<?php

namespace App\Http\Middleware;

use App\Domain\Analytics\Tracker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Website page views (Admin › Analytics), counted like Google Analytics: only when a real browser runs the page.
 *
 * The server prepares the view (path, route, referrer, campaign, country) under a one-time token printed in the
 * page (<meta name="arj-pv">). The page's script sends the token back once the page is visible (AnalyticsBeacon),
 * and only then is the view saved. Crawlers, link previews, uptime checks and scripts that only download HTML never
 * run it, so they are not counted; known bots are filtered by user agent before that, and counted separately so
 * the admin can see how much was filtered. The control centre is not tracked.
 */
class TrackVisit
{
    private const SKIP = ['admin', 'admin/*', 'staff/*', 'api/*', 'analytics/*', 'files/*', 'webhooks/*', 'up', 'ready', 'health*', 'sitemap.xml', 'robots.txt', 'llms*.txt', 'ads.txt', 'build/*', 'sw.js', 'manifest*', 'puja-photos/*', 'call/*'];

    /** How long a page may stay open before its view is reported (a tab opened in the background). */
    public const PENDING_MINUTES = 120;

    public function __construct(private Tracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $page = $request->isMethod('GET') && ! $request->is(...self::SKIP) && $this->tracker->enabled();
        $bot = $page && $this->tracker->isBot($request);
        $track = $page && ! $this->tracker->ignored($request);
        if ($track || ($request->isMethod('POST') && ! $request->is('admin/*', 'staff/*'))) {
            $request->attributes->set('analytics.ids', $this->tracker->identify($request));
        }
        if ($track) {
            $request->attributes->set('analytics.pv', Str::random(32));
        }
        $response = $next($request);
        $html = $response->isSuccessful() && str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
        if ($bot && $html) {
            Tracker::countFiltered('bots');
        }
        $ids = $request->attributes->get('analytics.ids');
        if (! $ids || ! $this->tracker->enabled() || $this->tracker->ignored($request)) {
            return $response;
        }
        $secure = (bool) config('session.secure');
        $response->headers->setCookie(new Cookie(Tracker::VISITOR_COOKIE, $ids['visitor'], now()->addYears(2), '/', null, $secure, true, false, Cookie::SAMESITE_LAX));
        $response->headers->setCookie(new Cookie(Tracker::SESSION_COOKIE, $ids['session'], now()->addMinutes(Tracker::SESSION_MINUTES), '/', null, $secure, true, false, Cookie::SAMESITE_LAX));
        if ($track && $html && ($token = $request->attributes->get('analytics.pv'))) {
            // Saved before the page is sent, so the browser's confirmation can never arrive first.
            rescue(fn () => Cache::put('an:pv:'.$token, $this->tracker->context($request, $ids) + [
                'is_new' => $ids['new_visitor'], 'path' => Tracker::cleanPath($request->path()), 'route' => $request->route()?->getName(),
                'referrer' => Tracker::referrer($request),
                'utm_source' => self::utm($request, 'utm_source'), 'utm_medium' => self::utm($request, 'utm_medium'), 'utm_campaign' => self::utm($request, 'utm_campaign'),
            ], now()->addMinutes(self::PENDING_MINUTES)), null, true);
            Tracker::countFiltered('served');
        }

        return $response;
    }

    private static function utm(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) && $v !== '' ? mb_substr(strip_tags($v), 0, 100) : null;
    }
}
