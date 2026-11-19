<?php

namespace App\Http\Controllers\Web;

use App\Domain\Identity\CustomerAuth;
use App\Domain\Identity\Otp\OtpException;
use App\Domain\Identity\Otp\OtpService;
use App\Domain\Notify\StaffAlerts;
use App\Domain\Privacy\PrivacyService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Public "Delete your account" page (/delete-account), the address given to Google Play.
 * Works without the app or a password: the person proves the email or mobile with a one-time code,
 * types DELETE, and the account is deleted at once (PrivacyService). If something blocks it (unpaid
 * order, upcoming consultation, wallet balance…), a case opens in Admin › Privacy requests and the team is alerted.
 */
class AccountDeletionController extends Controller
{
    private const PURPOSE = 'delete_account';

    public function show(Request $request, OtpService $otp): View
    {
        if ($request->boolean('restart')) {
            $request->session()->forget('delete_ref');
        }
        $ref = $request->session()->get('delete_ref');
        $c = $ref ? DB::table('otp_challenges')->where('ref', $ref)->whereNull('consumed_at')->where('expires_at', '>', now())->first() : null;

        return view('public.delete-account', ['step' => $c ? 'code' : 'start', 'masked' => $c ? $otp->maskedDestination($c->ref) : null,
            'signedIn' => $request->user() && ! $request->user()->isStaff()]);
    }

    public function start(Request $request, OtpService $otp): RedirectResponse
    {
        $d = $request->validate(['identifier' => ['required', 'string', 'max:190']]);
        ['channel' => $channel, 'destination' => $dest] = CustomerAuth::parseIdentifier($d['identifier']);
        try {
            // A code is sent whether or not an account exists, so the page does not reveal who has an account.
            $request->session()->put('delete_ref', $otp->issue($channel, $dest, self::PURPOSE, $request->ip()));
        } catch (OtpException $e) {
            return back()->withInput()->withErrors(['identifier' => $e->getMessage()]);
        }

        return redirect()->route('account-deletion.show')->with('status', __('We sent a 6-digit code. Enter it below to confirm.'));
    }

    public function confirm(Request $request, OtpService $otp, PrivacyService $privacy, StaffAlerts $alerts): RedirectResponse|View
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:10'], 'confirm' => ['required', 'in:DELETE']],
            ['confirm.in' => __('Type DELETE in capital letters to confirm.')]);
        $ref = (string) $request->session()->get('delete_ref');
        try {
            $c = $otp->verify($ref, $d['code'], self::PURPOSE);
        } catch (OtpException $e) {
            if (in_array($e->reason, ['expired', 'locked'], true)) {
                $request->session()->forget('delete_ref');
            }

            return back()->withErrors(['code' => $e->getMessage()]);
        }
        $request->session()->forget('delete_ref');
        $user = User::where($c['channel'] === 'email' ? 'email' : 'phone_e164', $c['destination'])->first();
        if (! $user || $user->isStaff() || $user->status === 'anonymized') {
            return redirect()->route('account-deletion.show')->with('status', __('There is no customer account with this email or mobile number, so there is nothing to delete.'));
        }

        $res = $privacy->delete($user);
        DB::table('privacy_requests')->where('id', $res->id)->update(['notes' => json_encode(array_merge(json_decode($res->notes ?? '[]', true) ?: [],
            [['at' => now()->toIso8601ZuluString(), 'by' => 'Website', 'text' => 'Requested on the public delete-account page ('.route('account-deletion.show').').']]))]);
        if ($res->status !== 'completed') {
            $alerts->send('privacy.handle', 'Account deletion request', 'A customer asked to delete their account on the website, but it cannot run yet: '.$res->blocked_reason, route('admin.privacy.show', $res->ref));

            return redirect()->route('account-deletion.show')->with('blocked', $res->blocked_reason);
        }
        $alerts->send('privacy.handle', 'Account deleted', 'A customer deleted their account from the website delete-account page.', route('admin.privacy.show', $res->ref), '');
        if ($request->user()?->id === $user->id) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return view('public.account-deleted', ['steps' => json_decode($res->steps, true), 'ref' => $res->ref]);
    }
}
