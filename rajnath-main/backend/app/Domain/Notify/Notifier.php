<?php

namespace App\Domain\Notify;

use App\Http\Controllers\Web\AccountController;
use App\Jobs\DeliverNotification;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One place to tell a customer something. Every notice is stored in the in-app inbox
 * (web account + app), then delivered by email and push (FCM) on the queue.
 * Optional categories respect the customer's notification preferences; service notices
 * (support replies, puja updates, account) are always sent.
 */
class Notifier
{
    /** kind → preference key (null = always sent). */
    public const PREF = [
        'booking' => 'booking_reminders', 'report' => 'reports_ready', 'palm' => 'reports_ready',
        'order' => 'payments', 'offer' => 'offers', 'puja' => null, 'call' => null, 'ticket' => null, 'review' => null, 'general' => null,
    ];

    /** @param list<string> $skip channels not to use for this message (email, push) */
    public function send(int|User $user, string $kind, string $title, string $body, ?string $ref = null, ?string $link = null, array $skip = []): ?int
    {
        $u = $user instanceof User ? $user : User::find($user);
        if (! $u || $u->status === 'anonymized') {
            return null;
        }
        $pref = self::PREF[$kind] ?? null;
        $prefs = $u->notification_prefs ? json_decode($u->notification_prefs, true) : AccountController::defaultPrefs();
        $wanted = $pref === null || ($prefs[$pref] ?? true);
        $id = DB::table('user_notifications')->insertGetId([
            'user_id' => $u->id, 'kind' => $kind, 'ref' => $ref, 'title' => mb_substr($title, 0, 160), 'body' => mb_substr($body, 0, 500),
            'link' => $link, 'channels' => json_encode($wanted ? array_fill_keys(array_values(array_intersect($skip, ['email', 'push'])), 'not_chosen') : ['email' => 'muted', 'push' => 'muted']), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($wanted) {
            DeliverNotification::dispatch($id)->afterCommit();
        }

        return $id;
    }
}
