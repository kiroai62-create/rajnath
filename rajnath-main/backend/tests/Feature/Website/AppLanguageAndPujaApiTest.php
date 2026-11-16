<?php

namespace Tests\Feature\Website;

use App\Http\Controllers\Api\V1\AppStringsController;
use App\Models\PujaRequest;
use App\Models\User;
use Database\Seeders\CatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\StaffHelpers;
use Tests\TestCase;

/** The app follows the language choice (its own text and our content) and has the website's puja request form. */
class AppLanguageAndPujaApiTest extends TestCase
{
    use RefreshDatabase, StaffHelpers;

    private function memory(string $locale, string $en, string $text): void
    {
        DB::table('site_translations')->updateOrInsert(['locale' => $locale, 'hash' => sha1($en)], ['source' => $en, 'text' => $text, 'status' => 'done', 'origin' => 'ai', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_app_strings_come_from_the_translation_memory_and_missing_ones_are_queued(): void
    {
        $catalogue = AppStringsController::catalogue();
        $this->assertContains('Puja on request', $catalogue['strings']);
        $this->assertContains('Press back again to close the app', $catalogue['strings']);
        $this->memory('hi', 'Puja on request', 'अनुरोध पर पूजा');

        $r = $this->getJson('/api/v1/app-strings?locale=hi')->assertOk();
        $this->assertSame('अनुरोध पर पूजा', $r->json('data.strings')['Puja on request']);
        $this->assertSame('भाषा', $r->json('data.strings')['Language']);              // reviewed lang/hi.json
        $this->assertTrue(DB::table('site_translations')->where('locale', 'hi')->where('source', 'Press back again to close the app')->where('status', 'pending')->exists());
        $this->assertSame([], (array) $this->getJson('/api/v1/app-strings?locale=en')->assertOk()->json('data.strings'));
        $this->getJson('/api/v1/app-strings?locale=xx')->assertNotFound();
    }

    public function test_public_api_content_follows_accept_language(): void
    {
        $this->seed(CatalogueSeeder::class);
        $en = $this->getJson('/api/v1/catalogue/offerings')->assertOk()->json('data');
        $hi = $this->getJson('/api/v1/catalogue/offerings', ['Accept-Language' => 'hi'])->assertOk()->json('data');
        // an offering with no Hindi text of its own
        $i = collect($en)->keys()->first(fn ($k) => $en[$k]['title'] === $hi[$k]['title']);
        $this->assertNotNull($i);
        $this->memory('hi', $en[$i]['title'], 'अनुवादित सेवा');

        $this->getJson('/api/v1/catalogue/offerings', ['Accept-Language' => 'hi'])->assertOk()->assertJsonPath("data.$i.title", 'अनुवादित सेवा')
            ->assertJsonPath("data.$i.slug", $en[$i]['slug']);
        $this->getJson('/api/v1/catalogue/offerings')->assertOk()->assertJsonPath("data.$i.title", $en[$i]['title']);
    }

    public function test_app_sends_a_puja_request_like_the_website_form(): void
    {
        $this->syncRbac();
        $this->withoutMiddleware(ThrottleRequests::class);
        $form = $this->getJson('/api/v1/puja-requests/form')->assertOk();
        $this->assertTrue($form->json('data.open'));
        $this->assertContains('Rudrabhishek', array_column($form->json('data.types'), 'value'));
        $this->assertContains('online', array_column($form->json('data.modes'), 'value'));

        $u = new User(['name' => 'Ramesh Kumar', 'email' => 'ramesh@example.com']);
        $u->save();
        $token = $u->createToken('Phone', ['customer'])->plainTextToken;
        $body = ['name' => 'Ramesh Kumar', 'phone' => '98765 43210', 'puja_type' => 'other', 'puja_other' => 'Chadhava at Baba Dham', 'mode' => 'home',
            'address' => 'Kankarbagh, Patna', 'preferred_date' => now('Asia/Kolkata')->addDays(3)->toDateString(), 'consent' => true];

        $this->withToken($token)->postJson('/api/v1/puja-requests', ['consent' => false] + $body)->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->withToken($token)->postJson('/api/v1/puja-requests', ['phone' => '123'] + $body)->assertUnprocessable()->assertJsonValidationErrors('phone');
        $sent = $this->withToken($token)->postJson('/api/v1/puja-requests', $body)->assertCreated();
        $this->assertSame('Chadhava at Baba Dham', $sent->json('data.puja_type'));
        $r = PujaRequest::firstOrFail();
        $this->assertSame($u->id, $r->user_id);
        $this->assertSame('+919876543210', $r->phone_e164);
        $this->assertSame('Kankarbagh, Patna', $r->address);

        $this->withToken($token)->getJson('/api/v1/me/puja-requests')->assertOk()->assertJsonPath('data.0.ref', $r->ref)->assertJsonPath('data.0.status', 'new');
        // visitors without an account can send one too
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/puja-requests', ['puja_type' => 'Rudrabhishek', 'mode' => 'online'] + $body)->assertCreated();
        $this->assertNull(PujaRequest::latest('id')->first()->user_id);
    }
}
