<?php

namespace App\Http\Middleware;

use App\Support\Google;
use App\Support\Settings\SettingsRepository;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(self), microphone=(self), geolocation=(), payment=(self), usb=(), serial=(), bluetooth=(), magnetometer=(), gyroscope=(), accelerometer=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        // Other sites may not load our pages/files as resources (images on social cards are fetched
        // by servers, which this header does not affect).
        $h->set('Cross-Origin-Resource-Policy', 'same-site');
        $h->set('X-Permitted-Cross-Domain-Policies', 'none');
        // Do not advertise the PHP version (deploy/php.ini also sets expose_php = Off).
        $h->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        $lk = '';
        if ($request->is('call/*')) {
            $u = (string) rescue(fn () => app(SettingsRepository::class)->get('calling.livekit.url'), '', false);
            // The configured LiveKit server: its WebSocket and the https fallback the SDK uses (/rtc/validate).
            if (preg_match('#^(wss?)://([a-z0-9.\-]+(:\d+)?)#i', $u, $m)) {
                $secure = strtolower($m[1]) === 'wss';
                $lk = ' '.strtolower($m[1]).'://'.$m[2].' '.($secure ? 'https' : 'http').'://'.$m[2];
            }
        }
        $dev = app()->environment('local') ? ' http://localhost:5173 ws://localhost:5173' : '';
        // Google Analytics and AdSense only where they run (Admin › Google & ads).
        $g = rescue(fn () => app(Google::class)->csp($request), ['script' => '', 'connect' => '', 'img' => '', 'frame' => '', 'strict' => false], false);
        // Ad pages: AdSense loads more scripts from changing Google hosts, so scripts started by our own nonce'd
        // tags are trusted ('strict-dynamic'); the host list stays for old browsers.
        $strict = $g['strict'] ? " 'strict-dynamic' https:" : '';
        $h->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-$nonce'$strict https://checkout.razorpay.com{$g['script']}$dev",
            "style-src 'self' 'unsafe-inline'$dev",
            "img-src 'self' data: blob: https://i.ytimg.com{$g['img']}",
            "font-src 'self'",
            "connect-src 'self' https://api.razorpay.com wss:$lk{$g['connect']}$dev",
            "frame-src https://api.razorpay.com https://checkout.razorpay.com https://www.youtube-nocookie.com{$g['frame']}",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "object-src 'none'",
            "worker-src 'self'",
            "manifest-src 'self'",
            ...($request->isSecure() ? ['upgrade-insecure-requests'] : []),
        ]));
        if ($request->is('admin/*', 'admin', 'staff/*', 'account/*')) {
            $h->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
