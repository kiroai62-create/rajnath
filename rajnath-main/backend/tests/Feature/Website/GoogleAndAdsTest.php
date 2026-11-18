<?php

namespace Tests\Feature\Website;

use App\Support\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\StaffHelpers;
use Tests\TestCase;

/** Admin › Google & ads: Google Analytics, Search Console, and AdSense on the free tool pages only. */
class GoogleAndAdsTest extends TestCase
{
    use RefreshDatabase, StaffHelpers;

    private const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncRbac();
    }

    private function save(array $data): TestResponse
    {
        return $this->asStaff($this->makeStaff(['owner']))->put(route('admin.google.update'), $data);
    }

    public function test_owner_adds_analytics_and_search_console(): void
    {
        $this->save(['google_ga_id' => 'not-an-id'])->assertSessionHasErrors('google_ga_id');
        $this->save([
            'google_ga_id' => 'g-abc123xyz',
            'seo_google_verification' => '<meta name="google-site-verification" content="AbC-123_xyz" />',
            'google_search_console_url' => 'https://search.google.com/search-console?resource_id=https://astrorajnathjha.com/',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $s = app(SettingsRepository::class);
        $this->assertSame('G-ABC123XYZ', $s->get('google.ga_id'));
        $this->assertSame('AbC-123_xyz', $s->get('seo.google_verification'));
        $this->assertFalse((bool) $s->get('ads.enabled'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $page = $this->withHeaders(['User-Agent' => self::UA])->get('/faq')->assertOk();
        $page->assertSee('<meta name="google-site-verification" content="AbC-123_xyz">', false)
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-ABC123XYZ', false)
            ->assertSee("gtag('consent','default'", false)->assertSee('x-data="consentBar"', false)->assertSee('Cookie settings');
        $csp = $page->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('https://www.googletagmanager.com', $csp);
        $this->assertStringContainsString('https://*.google-analytics.com', $csp);
        $this->assertStringNotContainsString('strict-dynamic', $csp);
        // no ads anywhere while ads are off
        $this->withHeaders(['User-Agent' => self::UA])->get('/tools')->assertOk()->assertDontSee('adsbygoogle', false);
        $this->get('/ads.txt')->assertNotFound();
        $this->asStaff($this->makeStaff(['owner']))->get(route('admin.google.edit'))->assertOk()->assertSee('Measurement ID')->assertSee('G-ABC123XYZ')->assertSee('Show ads');
    }

    public function test_ads_show_only_when_on_only_on_chosen_free_tool_pages_and_never_to_staff(): void
    {
        $this->save(['ads_enabled' => '1'])->assertSessionHasErrors('ads_enabled');
        $this->save(['ads_enabled' => '1', 'units' => ['result' => ['on' => '1', 'network' => 'adsense', 'mobile' => '1', 'desktop' => '1']]])
            ->assertSessionHasErrors(['ads_client', 'units.result.slot']);
        $this->save(['ads_enabled' => '1', 'ads_client' => 'pub-1234567890123456', 'ads_pages' => ['tools'],
            'units' => ['result' => ['on' => '1', 'network' => 'adsense', 'slot' => '9876543210', 'mobile' => '1', 'desktop' => '1']]])->assertSessionHasNoErrors();
        $this->assertSame('ca-pub-1234567890123456', app(SettingsRepository::class)->get('ads.client'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $tools = $this->withHeaders(['User-Agent' => self::UA])->get('/tools/numerology')->assertOk();
        $tools->assertSee('pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456', false)
            ->assertSee('data-ad-slot="9876543210"', false)->assertSee('Advertisement');
        $csp = $tools->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("'strict-dynamic'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
        $this->assertSame("google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0\n", $this->get('/ads.txt')->assertOk()->getContent());

        // pages outside the chosen groups and normal pages: no ads, no ad code, normal CSP
        foreach (['/panchang', '/faq', '/'] as $url) {
            $r = $this->withHeaders(['User-Agent' => self::UA])->get($url);
            $this->assertStringNotContainsString('adsbygoogle', (string) $r->getContent(), $url);
            $this->assertStringNotContainsString('strict-dynamic', (string) $r->headers->get('Content-Security-Policy'), $url);
        }
        // signed-in staff never see ads (no accidental clicks on our own ads)
        $this->asStaff($this->makeStaff(['administrator']))->get('/tools')->assertOk()->assertDontSee('adsbygoogle', false);

        // switching off removes them again
        $this->save(['ads_client' => 'ca-pub-1234567890123456', 'units' => ['result' => ['on' => '1', 'network' => 'adsense', 'slot' => '9876543210', 'mobile' => '1', 'desktop' => '1']]])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withHeaders(['User-Agent' => self::UA])->get('/tools')->assertOk()->assertDontSee('adsbygoogle', false);
    }

    public function test_several_places_with_different_networks_and_custom_code(): void
    {
        $this->save([
            'ads_enabled' => '1', 'ads_gam_network' => '21234567890', 'ads_medianet_cid' => '8cu1a2b3c', 'ads_pages' => ['tools', 'panchang'],
            'ads_head_code' => '<script src="https://example-ads.test/head.js"></script>',
            'ads_txt_extra' => "media.net, 8CU1A2B3C, DIRECT\nadsterra.com, 12345, DIRECT",
            'units' => [
                'top' => ['on' => '1', 'network' => 'gam', 'slot' => 'astro/tools_top', 'size' => '728x90, 320x50', 'mobile' => '1', 'desktop' => '1'],
                'side' => ['on' => '1', 'network' => 'medianet', 'slot' => '123456789', 'size' => '300x250', 'desktop' => '1'],
                'result' => ['on' => '1', 'network' => 'adsterra', 'slot' => 'abcdef0123456789abcdef0123456789', 'size' => '300x250', 'mobile' => '1', 'desktop' => '1'],
                'bottom' => ['on' => '1', 'network' => 'custom', 'code' => '<div class="taboola"></div><script>window.tb = 1;</script>', 'mobile' => '1'],
            ],
        ])->assertSessionHasNoErrors();
        $this->save(['ads_enabled' => '1', 'units' => ['top' => ['on' => '1', 'network' => 'gam', 'slot' => 'x', 'mobile' => '1']]])->assertSessionHasErrors(['ads_gam_network', 'units.top.size']);

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $page = $this->withHeaders(['User-Agent' => self::UA])->post('/tools/numerology', ['name' => 'Ravi Kumar', 'birth_date' => '1990-05-15'])->assertOk();
        $html = (string) $page->getContent();
        $this->assertStringContainsString('securepubads.g.doubleclick.net/tag/js/gpt.js', $html);
        $this->assertStringContainsString('data-gpt-unit="/21234567890/astro/tools_top"', $html);
        $this->assertStringContainsString('contextual.media.net/dmedianet.js?cid=8CU1A2B3C', $html);
        $this->assertStringContainsString('www.highperformanceformat.com/abcdef0123456789abcdef0123456789/invoke.js', $html);
        $this->assertStringContainsString('<div class="taboola"></div>', $html);
        // custom scripts get the page nonce so the security policy lets them run
        $this->assertMatchesRegularExpression('/<script nonce="[A-Za-z0-9]+">window\.tb = 1;<\/script>/', $html);
        $this->assertMatchesRegularExpression('#<script nonce="[A-Za-z0-9]+" src="https://example-ads\.test/head\.js">#', $html);
        $this->assertStringContainsString('lg:hidden', $html);   // the custom bottom ad is for phones only
        $this->assertSame("media.net, 8CU1A2B3C, DIRECT\nadsterra.com, 12345, DIRECT\n", $this->get('/ads.txt')->assertOk()->getContent());
        $this->assertStringNotContainsString('highperformanceformat', (string) $this->withHeaders(['User-Agent' => self::UA])->get('/faq')->getContent());

        $this->asStaff($this->makeStaff(['owner']))->get(route('admin.google.edit'))->assertOk()->assertSee('Google Ad Manager')->assertSee('Custom code (any network)')->assertSee('astro/tools_top');
    }

    public function test_only_settings_managers_change_google_settings(): void
    {
        $this->asStaff($this->makeStaff(['content_publisher']))->put(route('admin.google.update'), ['google_ga_id' => 'G-ABC123XYZ'])->assertForbidden();
        $this->assertNull(app(SettingsRepository::class)->get('google.ga_id'));
    }
}
