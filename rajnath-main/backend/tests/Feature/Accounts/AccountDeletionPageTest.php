<?php

namespace Tests\Feature\Accounts;

use App\Domain\Ledger\Ledger;
use App\Mail\OtpMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\StaffHelpers;
use Tests\TestCase;

/** Public /delete-account page (Google Play "Delete account URL"): works without the app, by one-time code. */
class AccountDeletionPageTest extends TestCase
{
    use RefreshDatabase, StaffHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->syncRbac();
        Mail::fake();
    }

    private function customer(string $email = 'asha@example.com'): User
    {
        $u = new User(['name' => 'Asha', 'email' => $email]);
        $u->forceFill(['kind' => User::KIND_CUSTOMER, 'email_verified_at' => now()])->save();

        return $u;
    }

    private function codeFor(string $email): string
    {
        $this->post('/delete-account', ['identifier' => $email])->assertRedirect(route('account-deletion.show'));
        $code = null;
        Mail::assertSent(OtpMail::class, function (OtpMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });

        return $code;
    }

    public function test_page_is_public_and_linked_in_the_footer(): void
    {
        $this->get('/delete-account')->assertOk()->assertSee('Delete your account')->assertSee('Privacy and your data')->assertSee('What is deleted and what is kept');
        $this->get('/')->assertSee(route('account-deletion.show'), false);
        $this->get('/delete-account?lang=hi')->assertOk();
    }

    public function test_customer_deletes_their_account_with_a_code_and_the_admin_sees_it(): void
    {
        $u = $this->customer();
        $code = $this->codeFor('asha@example.com');
        $this->get('/delete-account')->assertSee('Type DELETE to confirm');

        $this->post('/delete-account/confirm', ['code' => $code, 'confirm' => 'delete'])->assertSessionHasErrors('confirm');
        $this->post('/delete-account/confirm', ['code' => $code, 'confirm' => 'DELETE'])->assertOk()->assertSee('Your account has been deleted');

        $u->refresh();
        $this->assertSame('anonymized', $u->status);
        $this->assertNull($u->email);
        $case = DB::table('privacy_requests')->where('type', 'delete')->where('status', 'completed')->first();
        $this->assertStringContainsString('delete-account page', $case->notes);

        $owner = $this->makeStaff(['owner']);
        $this->asStaff($owner)->get(route('admin.privacy.index', ['show' => 'closed']))->assertOk()->assertSee('via website page')->assertSee(route('account-deletion.show'));
    }

    public function test_a_blocked_deletion_opens_a_case_for_the_team(): void
    {
        $u = $this->customer();
        $ledger = app(Ledger::class);
        $ledger->post('test', 'k1', [[$ledger->account('bonus_expense', 'expense'), 'debit', 50000], [$ledger->wallet($u->id), 'credit', 50000]]);

        $code = $this->codeFor('asha@example.com');
        $this->post('/delete-account/confirm', ['code' => $code, 'confirm' => 'DELETE'])->assertRedirect(route('account-deletion.show'))->assertSessionHas('blocked');
        $this->assertSame('active', $u->fresh()->status);
        $this->assertDatabaseHas('privacy_requests', ['user_id' => $u->id, 'type' => 'delete', 'status' => 'blocked', 'case_status' => 'open']);
    }

    public function test_wrong_code_and_unknown_address_never_delete_anything(): void
    {
        $u = $this->customer();
        $this->codeFor('asha@example.com');
        $this->post('/delete-account/confirm', ['code' => '000000', 'confirm' => 'DELETE'])->assertSessionHasErrors('code');
        $this->assertSame('active', $u->fresh()->status);

        // a code sent to an address with no account says so after the address is proven, and deletes nothing
        Mail::fake();
        $code = $this->codeFor('nobody@example.com');
        $this->post('/delete-account/confirm', ['code' => $code, 'confirm' => 'DELETE'])->assertRedirect(route('account-deletion.show'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'no customer account'));
        $this->assertSame('active', $u->fresh()->status);

        // a login code cannot be used to delete
        $this->post('/account/sign-in', ['identifier' => 'asha@example.com']);
        $this->post('/delete-account/confirm', ['code' => '123456', 'confirm' => 'DELETE'])->assertSessionHasErrors('code');
    }
}
