<?php

namespace Tests\Feature\Website;

use App\Domain\Analytics\GeoIp;
use App\Domain\Analytics\Tracker;
use App\Models\User;
use App\Support\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\StaffHelpers;
use Tests\TestCase;

/** First-party visitor analytics for the website and the app, shown in Admin › Analytics. */
class AnalyticsTest extends TestCase
{
    use RefreshDatabase, StaffHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncRbac();
        config(['analytics.trust_cdn_country' => true]);
    }

    private const PHONE = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';

    private const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0';

    /**
     * Opens a page like a browser: the server prepares the view, then the page's script confirms it.
     *
     * @return array{0: TestResponse, 1: ?string, 2: ?string} [page, visitor id, session id]
     */
    private function browse(string $url, array $headers, ?array $cookies = null, bool $runScript = true): array
    {
        $this->defaultCookies = [];
        $req = $this->withHeaders($headers);
        foreach ($cookies ?? [] as $k => $v) {
            $req = $req->withCookie($k, $v);
        }
        $page = $req->get($url);
        $vid = $page->getCookie(Tracker::VISITOR_COOKIE)?->getValue() ?? ($cookies[Tracker::VISITOR_COOKIE] ?? null);
        $sid = $page->getCookie(Tracker::SESSION_COOKIE)?->getValue() ?? ($cookies[Tracker::SESSION_COOKIE] ?? null);
        if ($runScript && preg_match('/<meta name="arj-pv" content="([A-Za-z0-9]{32})">/', (string) $page->getContent(), $m)) {
            $this->defaultCookies = [];
            $this->withHeaders($headers)->withCookie(Tracker::VISITOR_COOKIE, $vid)->withCookie(Tracker::SESSION_COOKIE, $sid)
                ->post(route('analytics.event'), ['type' => 'pageview', 'pv' => $m[1], 'path' => parse_url($url, PHP_URL_PATH)])->assertNoContent();
        }
        $this->flushHeaders();

        return [$page, $vid, $sid];
    }

    public function test_page_views_record_visitor_session_device_country_and_campaign(): void
    {
        [$first, $vid] = $this->browse('/faq?utm_source=instagram&utm_medium=social&utm_campaign=diwali',
            ['User-Agent' => self::PHONE, 'CF-IPCountry' => 'AE', 'Referer' => 'https://www.google.com/search?q=kundli']);
        $first->assertOk();
        $this->assertNotNull($vid);
        $e = DB::table('analytics_events')->where('type', 'pageview')->first();
        $this->assertSame('/faq', $e->path);
        $this->assertSame('faq', $e->route);
        $this->assertSame('mobile', $e->device);
        $this->assertSame('Android', $e->os);
        $this->assertSame('Chrome', $e->browser);
        $this->assertSame('AE', $e->country);
        $this->assertSame('google.com', $e->referrer);
        $this->assertSame('diwali', $e->utm_campaign);
        $this->assertSame($vid, $e->visitor);
        $this->assertSame(1, (int) $e->is_new);

        // a desktop visitor; then bots, the control centre and "do not track" are not counted
        $this->browse('/contact', ['User-Agent' => self::DESKTOP]);
        $this->browse('/contact', ['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']);
        $this->browse('/contact', ['User-Agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)']);
        $this->browse('/contact', ['User-Agent' => self::DESKTOP, 'Sec-GPC' => '1']);
        $owner = $this->makeStaff(['owner']);
        $this->asStaff($owner)->withHeaders(['User-Agent' => self::DESKTOP])->get('/admin');
        $this->assertSame(2, DB::table('analytics_events')->where('type', 'pageview')->count());
        $this->assertSame('Edge', DB::table('analytics_events')->where('path', '/contact')->value('browser'));

        $page = $this->asStaff($owner)->get(route('admin.analytics.website', ['days' => 7]))->assertOk();
        $page->assertSee('/faq')->assertSee('United Arab Emirates')->assertSee('google.com')->assertSee('instagram / social / diwali');
        $this->assertSame(2, $page->viewData('totals')['visitors']);
        $this->assertSame(2, $page->viewData('filtered')['bots']);
    }

    public function test_a_page_counts_only_when_a_real_browser_shows_it(): void
    {
        // A script or scraper downloads the HTML with a browser user agent but never runs the page: not counted
        $this->browse('/faq', ['User-Agent' => self::DESKTOP], null, runScript: false);
        $this->assertSame(0, DB::table('analytics_events')->where('type', 'pageview')->count());

        // The page token works once, and only for the browser that loaded the page
        $this->defaultCookies = [];
        $page = $this->withHeaders(['User-Agent' => self::PHONE])->get('/contact');
        preg_match('/<meta name="arj-pv" content="([A-Za-z0-9]{32})">/', (string) $page->getContent(), $m);
        $vid = $page->getCookie(Tracker::VISITOR_COOKIE)->getValue();
        $send = fn (string $visitor, string $token) => $this->withHeaders(['User-Agent' => self::PHONE])->withCookie(Tracker::VISITOR_COOKIE, $visitor)
            ->post(route('analytics.event'), ['type' => 'pageview', 'pv' => $token, 'path' => '/contact'])->assertNoContent();
        $send('someone-else-1234', $m[1]);
        $this->assertSame(0, DB::table('analytics_events')->where('type', 'pageview')->count());
        $this->defaultCookies = [];
        $this->flushHeaders();
        $page = $this->withHeaders(['User-Agent' => self::PHONE])->withCookie(Tracker::VISITOR_COOKIE, $vid)->get('/contact');
        preg_match('/<meta name="arj-pv" content="([A-Za-z0-9]{32})">/', (string) $page->getContent(), $m);
        $send($vid, $m[1]);
        $send($vid, $m[1]);
        $this->assertSame(1, DB::table('analytics_events')->where('type', 'pageview')->count());

        // Bots get no page token at all
        $this->defaultCookies = [];
        $bot = $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'])->get('/faq');
        $this->assertStringNotContainsString('arj-pv', (string) $bot->getContent());

        $f = $this->asStaff($this->makeStaff(['owner']))->get(route('admin.analytics.website'))->assertOk()->assertSee('Counted like Google Analytics')->viewData('filtered');
        $this->assertSame(1, $f['bots']);
        $this->assertSame(1, $f['confirmed']);
        $this->assertGreaterThanOrEqual(3, $f['served']);
        $this->assertNotNull(app(SettingsRepository::class)->get('analytics.confirmed_since'));
    }

    public function test_clicks_time_on_page_scroll_and_engagement_come_from_the_beacon(): void
    {
        [, $vid, $sid] = $this->browse('/faq', ['User-Agent' => self::PHONE]);
        $beacon = fn (array $d) => $this->withHeaders(['User-Agent' => self::PHONE])->withCookie(Tracker::VISITOR_COOKIE, $vid)->withCookie(Tracker::SESSION_COOKIE, $sid)
            ->post(route('analytics.event'), $d + ['path' => '/faq']);
        $beacon(['type' => 'click', 'label' => '  Free   Kundli ', 'target' => '/tools/kundli?name=Ravi#x'])->assertNoContent();
        $beacon(['type' => 'engage', 'seconds' => 75, 'depth' => 80])->assertNoContent();
        $beacon(['type' => 'click', 'label' => 'Call', 'target' => 'tel:+919835258203'])->assertNoContent();
        $click = DB::table('analytics_events')->where('type', 'click')->first();
        $this->assertSame('Free Kundli', $click->label);
        $this->assertSame('/tools/kundli', $click->target);   // the query string is never kept
        $this->assertSame('tel:', DB::table('analytics_events')->where('label', 'Call')->value('target'));
        $this->assertSame($sid, $click->session);
        // a second visitor reads one page for 3 seconds and leaves: not engaged (a bounce, as in Google Analytics)
        [, $v2, $s2] = $this->browse('/contact', ['User-Agent' => self::DESKTOP]);
        $this->withHeaders(['User-Agent' => self::DESKTOP])->withCookie(Tracker::VISITOR_COOKIE, $v2)->withCookie(Tracker::SESSION_COOKIE, $s2)
            ->post(route('analytics.event'), ['type' => 'engage', 'seconds' => 3, 'depth' => 20, 'path' => '/contact'])->assertNoContent();
        $this->flushHeaders();

        $page = $this->asStaff($this->makeStaff(['administrator']))->get(route('admin.analytics.website'))->assertOk()->assertSee('Free Kundli');
        $t = $page->viewData('totals');
        $this->assertSame(39, $t['avg_seconds']);
        $this->assertSame(1, $t['engaged_sessions']);
        $this->assertSame(50, $t['bounce_rate']);
        $this->assertSame(50, $t['avg_depth']);

        // a browser that never loaded a page (no visitor cookie) is ignored
        $this->defaultCookies = [];
        $this->withHeaders(['User-Agent' => self::PHONE])->post(route('analytics.event'), ['type' => 'click', 'path' => '/', 'label' => 'x'])->assertNoContent();
        $this->assertSame(2, DB::table('analytics_events')->where('type', 'click')->count());
    }

    public function test_visits_and_a_customers_full_activity_report(): void
    {
        $u = new User(['name' => 'Asha Verma', 'email' => 'asha@example.com']);
        $u->save();
        // before signing in, then signed in on the same browser
        [, $vid, $sid] = $this->browse('/faq', ['User-Agent' => self::PHONE]);
        $this->actingAs($u);
        $this->browse('/contact', ['User-Agent' => self::PHONE], [Tracker::VISITOR_COOKIE => $vid, Tracker::SESSION_COOKIE => $sid]);
        $this->app['auth']->forgetGuards();
        $owner = $this->makeStaff(['owner']);
        $list = $this->asStaff($owner)->get(route('admin.analytics.visits', ['signed_in' => 1]))->assertOk()->assertSee('Asha Verma');
        $this->assertSame(1, $list->viewData('rows')->total());
        $this->asStaff($owner)->get(route('admin.analytics.visit', $sid))->assertOk()->assertSee('/faq')->assertSee('/contact')->assertSee('Asha Verma');
        $report = $this->asStaff($owner)->get(route('admin.analytics.person', $u->id))->assertOk()->assertSee('/faq');
        $this->assertSame(2, (int) $report->viewData('summary')->pages);   // includes the page before signing in
        $this->assertDatabaseHas('audit_logs', ['action' => 'analytics.person_viewed']);
        $this->asStaff($this->makeStaff(['content_publisher']))->get(route('admin.analytics.person', $u->id))->assertForbidden();
    }

    public function test_old_bot_page_views_can_be_cleaned(): void
    {
        DB::table('analytics_events')->insert([
            ['occurred_at' => now()->subDays(3), 'type' => 'pageview', 'channel' => 'web', 'session' => 'bot-session-1'],
            ['occurred_at' => now()->subDays(3), 'type' => 'pageview', 'channel' => 'web', 'session' => 'human-session'],
            ['occurred_at' => now()->subDays(3), 'type' => 'engage', 'channel' => 'web', 'session' => 'human-session'],
        ]);
        app(SettingsRepository::class)->set('analytics.confirmed_since', now()->subDay()->toIso8601String());
        $this->artisan('arj:analytics-clean-bots', ['--dry-run' => true])->expectsOutputToContain('1 page views')->assertSuccessful();
        $this->artisan('arj:analytics-clean-bots')->assertSuccessful();
        $this->assertSame(['human-session'], DB::table('analytics_events')->where('type', 'pageview')->pluck('session')->all());
    }

    public function test_app_opens_features_and_screens(): void
    {
        $app = ['User-Agent' => 'Dart/3.9 (dart:io)', 'X-App-Install' => 'c0ffee00-1111-2222-3333-444455556666', 'X-App-Version' => '1.13.0', 'CF-IPCountry' => 'IN'];
        $this->withHeaders($app)->getJson('/api/v1/meta')->assertOk();
        $this->withHeaders($app)->getJson('/api/v1/faq')->assertOk();
        $this->withHeaders($app)->postJson('/api/v1/analytics/events', ['events' => [['type' => 'screen', 'name' => 'Panchang'], ['type' => 'screen', 'name' => 'Kundli']]])->assertNoContent();
        $this->flushHeaders()->withHeaders(['User-Agent' => 'Dart/3.9 (dart:io)'])->getJson('/api/v1/meta')->assertOk();   // an older build, no install id

        $this->assertSame(2, DB::table('analytics_events')->where('type', 'app_open')->count());
        $this->assertSame('1.13.0', DB::table('analytics_events')->where('type', 'app_open')->whereNotNull('app_version')->value('app_version'));
        $page = $this->asStaff($this->makeStaff(['owner']))->get(route('admin.analytics.app'))->assertOk()->assertSee('FAQ')->assertSee('Panchang')->assertSee('1.13.0');
        $this->assertSame(2, $page->viewData('totals')['devices']);
        // failed app requests (not found, validation, throttled) are not feature use
        $before = DB::table('analytics_events')->count();
        $this->withHeaders($app)->getJson('/api/v1/blog/no-such-article')->assertNotFound();
        $this->assertSame($before, DB::table('analytics_events')->count());
        // a country header is ignored unless the site is behind a CDN that sets it
        config(['analytics.trust_cdn_country' => false]);
        $this->withHeaders($app)->getJson('/api/v1/meta');
        $this->assertNull(DB::table('analytics_events')->orderByDesc('id')->value('country'));
        // website visits are not app opens, and the app does not count as website visitors
        $this->assertSame(0, DB::table('analytics_events')->where('channel', 'web')->count());
    }

    public function test_geoip_import_lookup_settings_and_retention(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'geo');
        file_put_contents($file, "1.0.0.0,1.0.0.255,AU\n49.32.0.0,49.47.255.255,IN\n2405:200::,2405:3ff:ffff:ffff:ffff:ffff:ffff:ffff,IN\n");
        $this->assertSame(0, Artisan::call('arj:geoip-import', ['--file' => $file]));
        $geo = app(GeoIp::class);
        $this->assertSame('IN', $geo->country('49.36.10.1'));
        $this->assertSame('AU', $geo->country('1.0.0.7'));
        $this->assertSame('IN', $geo->country('2405:201::1'));
        $this->assertNull($geo->country('8.8.8.8'));
        unlink($file);

        $owner = $this->makeStaff(['owner']);
        $this->asStaff($owner)->put(route('admin.analytics.settings'), ['enabled' => '0', 'retention_days' => 60])->assertRedirect();
        $this->browse('/faq', ['User-Agent' => self::PHONE]);
        $this->assertSame(0, DB::table('analytics_events')->count());

        app(SettingsRepository::class)->set('analytics.enabled', true);
        DB::table('analytics_events')->insert([['occurred_at' => now()->subDays(61), 'type' => 'pageview', 'channel' => 'web'], ['occurred_at' => now()->subDays(5), 'type' => 'pageview', 'channel' => 'web']]);
        Artisan::call('arj:analytics-prune');
        $this->assertSame(1, DB::table('analytics_events')->count());
    }

    public function test_only_permitted_staff_see_analytics(): void
    {
        $this->asStaff($this->makeStaff(['support']))->get(route('admin.analytics.website'))->assertForbidden();
        $this->asStaff($this->makeStaff(['content_publisher']))->get(route('admin.analytics.website'))->assertOk();
    }
}
