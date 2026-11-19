<?php

namespace Tests\Feature\Accounts;

use App\Domain\Identity\PlayReviewer;
use App\Domain\Ledger\Ledger;
use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\StaffHelpers;
use Tests\TestCase;

/** Google Play review login: fixed email + fixed code, customer-only, switchable from admin. */
class PlayReviewerTest extends TestCase
{
    use RefreshDatabase, StaffHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncRbac();
    }

    private function appSignIn(string $code): TestResponse
    {
        $challenge = $this->postJson('/api/v1/auth/otp/start', ['identifier' => PlayReviewer::EMAIL])->assertCreated()->json('challenge');

        return $this->postJson('/api/v1/auth/otp/verify', ['challenge' => $challenge, 'code' => $code, 'device_name' => 'Pixel', 'platform' => 'android']);
    }

    public function test_reviewer_signs_in_to_the_app_with_the_fixed_code_and_has_wallet_credit(): void
    {
        Mail::fake();
        $login = app(PlayReviewer::class)->setUp();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $login['code']);

        $this->postJson('/api/v1/auth/otp/start', ['identifier' => PlayReviewer::EMAIL])->assertCreated()->assertJsonPath('is_new_account', false);
        $token = $this->appSignIn($login['code'])->assertOk()->json('token');
        Mail::assertNotSent(OtpMail::class);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(PlayReviewer::CREDIT_MINOR, app(Ledger::class)->walletBalance(app(PlayReviewer::class)->user()->id));

        // repeated sign-ins are not blocked by the per-address hourly limit
        for ($i = 0; $i < 6; $i++) {
            $this->travel(2)->minutes();
            $this->postJson('/api/v1/auth/otp/start', ['identifier' => PlayReviewer::EMAIL])->assertCreated();
        }

        // setting up again keeps the code and tops the wallet back up; it is never a staff account
        $this->assertSame($login['code'], app(PlayReviewer::class)->setUp()['code']);
        $user = app(PlayReviewer::class)->user();
        $this->assertSame(User::KIND_CUSTOMER, $user->kind);
        $this->assertCount(0, $user->roles);
    }

    public function test_wrong_code_fails_and_turning_off_ends_the_fixed_code(): void
    {
        Mail::fake();
        $login = app(PlayReviewer::class)->setUp();
        $wrong = $login['code'] === '000000' ? '111111' : '000000';
        $this->appSignIn($wrong)->assertUnprocessable();
        $token = $this->appSignIn($login['code'])->assertOk()->json('token');

        app(PlayReviewer::class)->disable();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
        // when off, the email gets a normal random code by email, so the fixed code no longer works
        $this->appSignIn($login['code'])->assertUnprocessable();
        Mail::assertSent(OtpMail::class);
    }

    public function test_too_many_wrong_codes_switch_the_login_off(): void
    {
        $login = app(PlayReviewer::class)->setUp();
        for ($i = 0; $i < PlayReviewer::MAX_FAILURES; $i++) {
            app(PlayReviewer::class)->recordFailure();
        }
        $this->assertFalse(app(PlayReviewer::class)->enabled());
        $this->assertNotEmpty($login['code']);
    }

    public function test_only_the_owner_sees_and_controls_it_in_admin(): void
    {
        $owner = $this->makeStaff(['owner']);
        $this->asStaff($owner)->post(route('admin.app.reviewer'), ['action' => 'on'])->assertRedirect()->assertSessionHas('status');
        $code = app(PlayReviewer::class)->code();
        $this->asStaff($owner)->get(route('admin.app.edit'))->assertOk()->assertSee('Google Play review login')->assertSee(PlayReviewer::EMAIL)->assertSee($code);

        $editor = $this->makeStaff(['content_publisher']);
        $this->asStaff($editor)->get(route('admin.app.edit'))->assertOk()->assertDontSee($code)->assertSee('Only the owner can see this');
        $this->asStaff($editor)->post(route('admin.app.reviewer'), ['action' => 'off'])->assertForbidden();

        $this->artisan('arj:play-reviewer --off')->assertSuccessful();
        $this->assertFalse(app(PlayReviewer::class)->enabled());
    }
}
