<?php

namespace App\Jobs;

use App\Domain\Notify\Notifier;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Admin › Push messages: sends one message to every active customer. Each customer gets it in
 * the app inbox, and by push and email through the normal Notifier (offers respect the
 * customer's "offers" preference). Customers who already have this message are skipped, so
 * a retried job never sends twice.
 */
class SendBroadcast implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    /** @param list<string> $skip channels not used (push, email) */
    public function __construct(public string $ref, public string $kind, public string $title, public string $body, public ?string $link,
        public string $audience = 'all', public ?int $userId = null, public array $skip = []) {}

    public function handle(Notifier $notifier): void
    {
        $app = fn ($q) => $q->select('tokenable_id')->from('personal_access_tokens')->where('tokenable_type', User::class);
        User::query()->where('kind', User::KIND_CUSTOMER)->where('status', User::STATUS_ACTIVE)
            ->when($this->audience === 'one', fn ($w) => $w->where('id', $this->userId ?? 0))
            ->when($this->audience === 'app', fn ($w) => $w->whereIn('id', $app))
            ->when($this->audience === 'web', fn ($w) => $w->whereNotIn('id', $app))
            ->whereNotExists(fn ($q) => $q->from('user_notifications')->whereColumn('user_notifications.user_id', 'users.id')->where('user_notifications.ref', $this->ref))
            ->select('id')->chunkById(200, function ($users) use ($notifier) {
                foreach ($users as $u) {
                    $notifier->send($u->id, $this->kind, $this->title, $this->body, $this->ref, $this->link, $this->skip);
                }
            });
    }
}
