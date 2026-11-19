<?php

namespace App\Domain\Identity;

use App\Domain\Ledger\Ledger;
use App\Models\BirthProfile;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Settings\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Google Play "App access" login for the app review team. Customers sign in with a one-time code, so the
 * reviewer gets one fixed email whose sign-in code is fixed too (no email is sent for it).
 *
 * - The code is random per server, stored encrypted in settings, never in the repository.
 * - It works only for this one customer account (no staff roles, so no admin access) and only while switched on
 *   in Admin › Mobile app. Too many wrong codes switch it off.
 * - The account gets wallet credit and a saved birth profile, so the reviewer can open every paid feature
 *   (reports, consultations, puja requests) without a real payment. Orders show the name "Google Play Reviewer".
 */
class PlayReviewer
{
    public const EMAIL = 'play-review@astrorajnathjha.com';

    public const NAME = 'Google Play Reviewer (test account)';

    public const CREDIT_MINOR = 1000000; // ₹10,000 wallet credit, topped up each time the login is set up

    public const MAX_FAILURES = 50; // wrong codes in 24 hours before the login switches itself off

    public function __construct(private SettingsRepository $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('auth.reviewer.enabled', false) && $this->code() !== null;
    }

    public function code(): ?string
    {
        $code = $this->settings->get('auth.reviewer.code');

        return is_string($code) && preg_match('/^\d{6}$/', $code) ? $code : null;
    }

    /** True for the reviewer's email on a login challenge while the reviewer login is on. */
    public function matches(string $channel, string $destination, string $purpose): bool
    {
        return $purpose === 'login' && $channel === 'email' && mb_strtolower($destination) === self::EMAIL && $this->enabled();
    }

    public function user(): ?User
    {
        return User::where('email', self::EMAIL)->first();
    }

    public function recordFailure(): void
    {
        Cache::add('reviewer-fails', 0, now()->addDay());
        if (Cache::increment('reviewer-fails') >= self::MAX_FAILURES && $this->settings->get('auth.reviewer.enabled')) {
            $this->settings->set('auth.reviewer.enabled', false, false, 'auth');
            app(AuditLogger::class)->record('reviewer.auto_disabled', null, ['failures' => self::MAX_FAILURES]);
        }
    }

    /**
     * Creates or refreshes the reviewer account and switches the login on.
     *
     * @return array{email: string, code: string}
     */
    public function setUp(bool $newCode = false, ?User $by = null): array
    {
        $code = $newCode || ! $this->code() ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT) : $this->code();

        $user = DB::transaction(function () {
            $user = $this->user() ?? new User;
            if ($user->exists && $user->isStaff()) {
                throw new \RuntimeException('The reviewer email belongs to a staff account.');
            }
            $user->forceFill([
                'name' => self::NAME, 'email' => self::EMAIL, 'kind' => User::KIND_CUSTOMER, 'status' => User::STATUS_ACTIVE,
                'email_verified_at' => $user->email_verified_at ?? now(), 'terms_accepted_at' => $user->terms_accepted_at ?? now(),
                'signup_channel' => $user->signup_channel ?? 'app', 'locale' => $user->locale ?? 'en',
            ])->save();
            $user->syncRoles([]);
            $patna = DB::table('places')->where('name', 'Patna')->where('country_code', 'IN')->orderByDesc('population')->value('id');
            if ($patna && ! BirthProfile::where('user_id', $user->id)->exists()) {
                app(BirthProfiles::class)->save($user, ['label' => 'self', 'name' => 'Review Sample', 'gender' => 'male', 'birth_date' => '1990-05-14',
                    'time_accuracy' => 'exact', 'birth_time' => '06:30', 'place_id' => $patna]);
            }

            return $user;
        });

        $ledger = app(Ledger::class);
        $topUp = self::CREDIT_MINOR - $ledger->walletBalance($user->id);
        if ($topUp > 0) {
            $ledger->post('reviewer_credit', 'reviewer-credit:'.$user->id.':'.now()->format('YmdHis'), [
                [$ledger->account('bonus_expense', 'expense'), 'debit', $topUp], [$ledger->wallet($user->id), 'credit', $topUp],
            ], 'Google Play review test credit');
        }

        $this->settings->set('auth.reviewer.code', $code, true, 'auth');
        $this->settings->set('auth.reviewer.enabled', true, false, 'auth');
        Cache::forget('reviewer-fails');
        app(AuditLogger::class)->record('reviewer.set_up', null, ['new_code' => $newCode], $by);

        return ['email' => self::EMAIL, 'code' => $code];
    }

    public function disable(?User $by = null): void
    {
        $this->settings->set('auth.reviewer.enabled', false, false, 'auth');
        $user = $this->user();
        if ($user) {
            $user->tokens()->delete();
        }
        app(AuditLogger::class)->record('reviewer.disabled', null, [], $by);
    }
}
