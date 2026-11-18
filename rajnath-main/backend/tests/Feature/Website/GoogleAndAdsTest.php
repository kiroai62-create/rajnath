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
        $this->save(['ads_enabled' => '1'])->assertSessionHasErrors(['ads_client', 'ads_slot_content']);
        $this->save(['ads_enabled' => '1', 'ads_client' => 'pub-1234567890123456', 'ads_slot_content' => '9876543210', 'ads_pages' => ['tools']])
            ->assertSessionHasNoErrors();
        $this->assertSame('ca-pub-1234567890123456', app(SettingsRepository::class)->get('ads.client'));

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $tools = $this->withHeaders(['User-Agent' => self::UA])->get('/tools')->assertOk();
        $tools->assertSee('pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1234567890123456', false)
            ->assertSee('data-ad-slot="9876543210"', false)->assertSee('Advertisement');
        $csp = $tools->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("'strict-dynamic'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]+'/", $csp);
        $this->assertStringContainsString('https://*.googlesyndication.com', $csp);
        $this->assertSame("google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0\n", $this->get('/ads.txt')->assertOk()->getContent());

        // pages outside the chosen groups and normal pages: no ads, no AdSense code, normal CSP
        foreach (['/panchang', '/faq', '/'] as $url) {
            $r = $this->withHeaders(['User-Agent' => self::UA])->get($url);
            $this->assertStringNotContainsString('adsbygoogle', (string) $r->getContent(), $url);
            $this->assertStringNotContainsString('strict-dynamic', (string) $r->headers->get('Content-Security-Policy'), $url);
        }
        // signed-in staff never see ads (no accidental clicks on our own ads)
        $this->asStaff($this->makeStaff(['administrator']))->get('/tools')->assertOk()->assertDontSee('adsbygoogle', false);

        // switching off removes them again
        $this->save(['ads_client' => 'ca-pub-1234567890123456', 'ads_slot_content' => '9876543210'])->assertSessionHasNoErrors();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withHeaders(['User-Agent' => self::UA])->get('/tools')->assertOk()->assertDontSee('adsbygoogle', false);
    }

    public function test_only_settings_managers_change_google_settings(): void
    {
        $this->asStaff($this->makeStaff(['content_publisher']))->put(route('admin.google.update'), ['google_ga_id' => 'G-ABC123XYZ'])->assertForbidden();
        $this->assertNull(app(SettingsRepository::class)->get('google.ga_id'));
    }
}
