<?php

namespace App\Domain\Booking;

use App\Domain\Ledger\Ledger;
use App\Domain\Meetings\Meetings;
use App\Domain\Notify\Notifier;
use App\Domain\Payments\Checkout;
use App\Domain\Payments\Refunds;
use App\Domain\Practitioners\Payouts;
use App\Domain\Practitioners\Practitioners;
use App\Domain\Reviews\Reviews;
use App\Jobs\SyncMeeting;
use App\Models\OfferingVariant;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Consultation slot booking for the principal practitioner.
 * - Availability: weekly rules in office time (Asia/Kolkata) minus leave dates.
 * - Policy (config/booking.php): minimum lead time, 15-minute payment hold, cancel/reschedule
 *   allowed until N hours before start; cancellations refund in full to the wallet.
 * - Double booking is prevented by a UNIQUE index on booking_units.unit_start.
 */
class Slots
{
    public const TZ = 'Asia/Kolkata';

    public const SLOT_MODES = ['audio', 'video', 'in_person', 'chat'];

    public function __construct(private Checkout $checkout, private Ledger $ledger, private AuditLogger $audit) {}

    public static function bookable(OfferingVariant $v): bool
    {
        return in_array($v->mode, self::SLOT_MODES, true) && $v->duration_min > 0;
    }

    /** @return array<string, list<array{start: string, label: string}>> keyed by local date */
    public function available(int $durationMin, int $days = 21, int $resource = 0): array
    {
        $rules = DB::table('availability_rules')->where('resource_key', $resource)->where('active', true)->get()->groupBy('weekday');
        $leave = DB::table('availability_exceptions')->where('resource_key', $resource)->pluck('date')->map(fn ($d) => substr($d, 0, 10))->flip();
        $taken = DB::table('booking_units')->where('resource_key', $resource)->where('unit_start', '>=', now('UTC')->subDay())->pluck('unit_start')->map(fn ($t) => substr($t, 0, 16))->flip();
        $earliest = CarbonImmutable::now('UTC')->addMinutes(config('booking.lead_minutes'));
        $out = [];
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        for ($d = 0; $d < $days; $d++) {
            $day = $today->addDays($d);
            if (isset($leave[$day->toDateString()])) {
                continue;
            }
            foreach ($rules[$day->dayOfWeek] ?? [] as $rule) {
                $step = (int) $rule->slot_minutes;
                $units = (int) ceil($durationMin / $step);
                $cursor = CarbonImmutable::parse($day->toDateString().' '.$rule->start_local, self::TZ);
                $end = CarbonImmutable::parse($day->toDateString().' '.$rule->end_local, self::TZ);
                while ($cursor->addMinutes($units * $step)->lte($end)) {
                    $utc = $cursor->utc();
                    $free = $utc->gte($earliest);
                    for ($u = 0; $free && $u < $units; $u++) {
                        $free = ! isset($taken[$utc->addMinutes($u * $step)->format('Y-m-d H:i')]);
                    }
                    if ($free) {
                        $out[$day->toDateString()][] = ['start' => $utc->format('Y-m-d\TH:i\Z'), 'label' => $cursor->format('g:i A')];
                    }
                    $cursor = $cursor->addMinutes($step);
                }
            }
        }

        return $out;
    }

    /** Holds the slot and creates the order. Returns [booking, checkout payload]. */
    public function hold(User $user, OfferingVariant $v, string $startIso, ?int $birthProfileId, ?string $topic, string $key, bool $useWallet = false, ?string $practitionerRef = null, ?string $coupon = null): array
    {
        if (! self::bookable($v)) {
            throw ValidationException::withMessages(['start' => 'This option is not booked by time slot.']);
        }
        $practitioner = Practitioners::forBooking($v, $practitionerRef);
        $resource = (int) ($practitioner->id ?? 0);
        if (DB::table('bookings')->where('user_id', $user->id)->where('status', 'held')->where('hold_expires_at', '>', now())->count() >= 2) {
            throw ValidationException::withMessages(['start' => 'You already have unpaid bookings on hold. Complete or wait for them to expire first.']);
        }
        $start = CarbonImmutable::parse($startIso)->utc();
        $slotsForDay = $this->available((int) $v->duration_min, 31, $resource)[$start->setTimezone(self::TZ)->toDateString()] ?? [];
        if (! collect($slotsForDay)->contains('start', $start->format('Y-m-d\TH:i\Z'))) {
            throw ValidationException::withMessages(['start' => 'This time is no longer available. Please choose another slot.']);
        }
        $step = $this->step($resource, $start);
        $units = (int) ceil($v->duration_min / $step);
        try {
            $booking = DB::transaction(function () use ($user, $v, $start, $birthProfileId, $topic, $units, $step, $resource) {
                $id = DB::table('bookings')->insertGetId([
                    'ref' => (string) Str::ulid(), 'user_id' => $user->id, 'variant_id' => $v->id, 'start_utc' => $start, 'end_utc' => $start->addMinutes($v->duration_min),
                    'status' => 'held', 'hold_expires_at' => now()->addMinutes(config('booking.hold_minutes')), 'mode' => $v->mode,
                    'birth_profile_id' => $birthProfileId, 'topic' => $topic, 'practitioner_id' => $resource ?: null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                for ($u = 0; $u < $units; $u++) {
                    DB::table('booking_units')->insert(['booking_id' => $id, 'resource_key' => $resource, 'unit_start' => $start->addMinutes($u * $step)]);
                }

                return DB::table('bookings')->find($id);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) {
                throw ValidationException::withMessages(['start' => 'Someone just booked this time. Please choose another slot.']);
            }
            throw $e;
        }
        try {
            $r = $this->checkout->startServiceOrder($user, $v, $useWallet, $key, ['booking_ref' => $booking->ref, 'start_utc' => $start->format('Y-m-d\TH:i\Z')] + ($practitioner ? ['practitioner' => ['ref' => $practitioner->ref, 'name' => $practitioner->display_name]] : []), $coupon);
        } catch (\Throwable $e) {
            $this->release($booking->id, 'cancelled');
            throw $e;
        }
        if ((json_decode($r['order']->snapshot, true)['booking_ref'] ?? null) !== $booking->ref) {
            // the idempotency key belonged to an earlier attempt: keep that one, drop this hold
            $this->release($booking->id, 'cancelled');
            throw ValidationException::withMessages(['start' => 'This form was already submitted. Please reload the page and choose a time again.']);
        }
        DB::table('bookings')->where('id', $booking->id)->whereNull('order_id')->update(['order_id' => $r['order']->id]);
        $this->audit->record('booking.held', null, ['booking' => $booking->ref], $user);

        return [DB::table('bookings')->find($booking->id), $r];
    }

    public function onOrderPaid(object $order): void
    {
        // Matched by the ref in the order snapshot: a fully wallet-paid order is marked paid before
        // hold() has linked order_id to the booking.
        $ref = json_decode($order->snapshot, true)['booking_ref'] ?? null;
        $n = DB::table('bookings')->where('ref', $ref)->where('user_id', $order->user_id)->where('status', 'held')
            ->update(['status' => 'confirmed', 'order_id' => $order->id, 'hold_expires_at' => null, 'updated_at' => now()]);
        if ($n) {
            $b = DB::table('bookings')->where('ref', $ref)->first();
            $online = in_array($b->mode, Meetings::ONLINE_MODES, true);
            $this->notify($b, 'Consultation confirmed', 'Your consultation is confirmed for '.self::when($b).'.'.($online ? ' Your call link is in My bookings and will also be sent to you.' : ' Please come to the office on time.'));
            SyncMeeting::dispatch($b->id, 'confirm')->afterCommit();
        }
    }

    public function onOrderCancelled(object $order): void
    {
        $b = DB::table('bookings')->where('order_id', $order->id)->where('status', 'held')->first();
        if ($b) {
            $this->release($b->id, 'cancelled');
        }
    }

    /** Scheduled every minute: unpaid holds past their expiry free the slot and cancel the order. */
    public function expireHolds(): int
    {
        $n = 0;
        foreach (DB::table('bookings')->where('status', 'held')->where('hold_expires_at', '<', now())->get() as $b) {
            if ($b->order_id) {
                // cancel() only acts on pending_payment orders and releases the hold via onOrderCancelled.
                // If the payment landed meanwhile the order is paid and the booking stays (confirmed by onOrderPaid).
                $this->checkout->cancel(DB::table('orders')->find($b->order_id));
                $n += (int) (DB::table('bookings')->where('id', $b->id)->value('status') === 'cancelled');
            } elseif ($this->release($b->id, 'cancelled', 'held')) {
                $n++;
            }
        }

        return $n;
    }

    /** Frees the units and sets the status, only if the booking is still in $from. */
    private function release(int $bookingId, string $status, string $from = 'held'): bool
    {
        return DB::transaction(function () use ($bookingId, $status, $from) {
            if (! DB::table('bookings')->where('id', $bookingId)->where('status', $from)->update(['status' => $status, 'updated_at' => now()])) {
                return false;
            }
            DB::table('booking_units')->where('booking_id', $bookingId)->delete();

            return true;
        });
    }

    private function changeable(object $b): bool
    {
        return $b->status === 'confirmed' && CarbonImmutable::parse($b->start_utc, 'UTC')->subHours(config('booking.change_hours'))->isFuture();
    }

    public function cancelByCustomer(object $b, User $u): void
    {
        abort_unless($b->user_id === $u->id, 404);
        if (! $this->changeable($b)) {
            throw ValidationException::withMessages(['booking' => 'Bookings can be cancelled up to '.config('booking.change_hours').' hours before the start time. Please contact support.']);
        }
        DB::transaction(function () use ($b) {
            if (! $this->release($b->id, 'cancelled', 'confirmed')) {
                throw ValidationException::withMessages(['booking' => 'This booking was already changed.']);
            }
            $order = DB::table('orders')->where('id', $b->order_id)->lockForUpdate()->first();
            app(Refunds::class)->policyRefundToWallet($order, (int) $order->subtotal_minor - (int) $order->refunded_minor, 'Cancelled by customer within policy');
        });
        $this->audit->record('booking.cancelled', null, ['booking' => $b->ref], $u);
        SyncMeeting::dispatch($b->id, 'cancel')->afterCommit();
        $this->notify($b, 'Booking cancelled', 'Your consultation on '.self::when($b).' was cancelled and the full amount was added to your wallet.');
    }

    public function reschedule(object $b, User $u, string $newStartIso): void
    {
        abort_unless($b->user_id === $u->id, 404);
        if (! $this->changeable($b) || $b->reschedules >= config('booking.max_reschedules')) {
            throw ValidationException::withMessages(['start' => 'This booking can no longer be rescheduled online. Please contact support.']);
        }
        $v = OfferingVariant::findOrFail($b->variant_id);
        $resource = (int) ($b->practitioner_id ?? 0);
        $start = CarbonImmutable::parse($newStartIso)->utc();
        if (! collect($this->available((int) $v->duration_min, 31, $resource)[$start->setTimezone(self::TZ)->toDateString()] ?? [])->contains('start', $start->format('Y-m-d\TH:i\Z'))) {
            throw ValidationException::withMessages(['start' => 'This time is no longer available.']);
        }
        $step = $this->step($resource, $start);
        try {
            DB::transaction(function () use ($b, $start, $v, $step, $resource) {
                DB::table('booking_units')->where('booking_id', $b->id)->delete();
                for ($u = 0; $u < (int) ceil($v->duration_min / $step); $u++) {
                    DB::table('booking_units')->insert(['booking_id' => $b->id, 'resource_key' => $resource, 'unit_start' => $start->addMinutes($u * $step)]);
                }
                DB::table('bookings')->where('id', $b->id)->update(['start_utc' => $start, 'end_utc' => $start->addMinutes($v->duration_min), 'reschedules' => $b->reschedules + 1, 'updated_at' => now()]);
            });
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['start' => 'Someone just booked this time. Please choose another slot.']);
        }
        $this->audit->record('booking.rescheduled', null, ['booking' => $b->ref], $u);
        SyncMeeting::dispatch($b->id, 'reschedule')->afterCommit();
        $this->notify(DB::table('bookings')->find($b->id), 'Booking moved', 'Your consultation is now on '.self::when(DB::table('bookings')->find($b->id)).'.');
    }

    /**
     * The team moves a confirmed booking to any time, for example to today when Acharya Ji and the customer both want
     * the consultation earlier. Unlike the customer's reschedule it ignores the lead time, the change window, the
     * reschedule limit, office hours and leave days. It still refuses a time that overlaps another booking.
     */
    public function moveByStaff(object $b, string $localDateTime, ?string $note, User $staff): object
    {
        if ($b->status !== 'confirmed') {
            throw ValidationException::withMessages(['start_local' => 'Only confirmed bookings can be moved.']);
        }
        $start = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $localDateTime, self::TZ)->utc()->startOfMinute();
        if ($start->lt(CarbonImmutable::now('UTC')->subMinutes(15))) {
            throw ValidationException::withMessages(['start_local' => 'Choose the current time or a later time.']);
        }
        $duration = (int) CarbonImmutable::parse($b->start_utc, 'UTC')->diffInMinutes(CarbonImmutable::parse($b->end_utc, 'UTC'));
        $duration = $duration > 0 ? $duration : 30;
        $end = $start->addMinutes($duration);
        $resource = (int) ($b->practitioner_id ?? 0);
        $units = $this->unitsCovering($resource, $start, $end);
        $clash = DB::table('booking_units as u')->join('bookings as o', 'o.id', '=', 'u.booking_id')
            ->where('u.resource_key', $resource)->whereIn('u.unit_start', $units)->where('u.booking_id', '!=', $b->id)
            ->whereIn('o.status', ['held', 'confirmed'])->select('o.start_utc')->first();
        if ($clash) {
            throw ValidationException::withMessages(['start_local' => 'This time overlaps another booking at '.CarbonImmutable::parse($clash->start_utc, 'UTC')->setTimezone(self::TZ)->format('D d M, g:i A').' IST. Choose another time.']);
        }
        $now = CarbonImmutable::now('UTC');
        try {
            DB::transaction(function () use ($b, $start, $end, $units, $resource, $note, $now) {
                DB::table('booking_units')->where('booking_id', $b->id)->delete();
                foreach ($units as $u) {
                    DB::table('booking_units')->insert(['booking_id' => $b->id, 'resource_key' => $resource, 'unit_start' => $u]);
                }
                DB::table('bookings')->where('id', $b->id)->update([
                    'start_utc' => $start, 'end_utc' => $end,
                    'moved_from_utc' => $b->moved_from_utc ?? $b->start_utc, 'moved_note' => $note,
                    // The move message tells the customer the new time, so reminders that are already due are skipped.
                    'reminded_24h_at' => $start->lte($now->addHours(24)) ? $now : null,
                    'reminded_1h_at' => $start->lte($now->addHour()) ? $now : null,
                    'updated_at' => $now,
                ]);
            });
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['start_local' => 'Someone just booked this time. Choose another time.']);
        }
        $moved = DB::table('bookings')->find($b->id);
        $this->audit->record('booking.moved_by_staff', null, ['booking' => $b->ref, 'from' => (string) $b->start_utc, 'to' => $start->format('Y-m-d H:i:s')], $staff);
        SyncMeeting::dispatch($b->id, 'reschedule')->afterCommit();
        $today = $start->setTimezone(self::TZ)->isSameDay(CarbonImmutable::now(self::TZ));
        $this->notify($moved, $today ? 'Your consultation is today' : 'Booking moved',
            'Our team has moved your consultation to '.self::when($moved).'.'.($note ? ' '.$note : '').' You can join from My bookings.');

        return $moved;
    }

    /**
     * Slot units that cover [start, end) on the slot grid of that day, so a booking moved to an off-grid time
     * (for example 2:10 PM) still blocks the 2:00 and 2:30 slots for other customers.
     *
     * @return list<CarbonImmutable>
     */
    private function unitsCovering(int $resource, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $local = $start->setTimezone(self::TZ);
        $rule = DB::table('availability_rules')->where('resource_key', $resource)->where('weekday', $local->dayOfWeek)->orderBy('start_local')->first();
        $step = (int) ($rule->slot_minutes ?? 30) ?: 30;
        $origin = CarbonImmutable::parse($local->toDateString().' '.($rule->start_local ?? '00:00'), self::TZ)->utc();
        $k = (int) floor($origin->diffInMinutes($start, false) / $step);
        $out = [];
        for ($t = $origin->addMinutes($k * $step); $t->lt($end); $t = $t->addMinutes($step)) {
            $out[] = $t;
        }

        return $out;
    }

    public function setOutcome(object $b, string $status, ?string $link, User $staff): void
    {
        $data = ['updated_at' => now()];
        if ($link !== null && $link !== $b->meeting_link) {
            app(Meetings::class)->setManual($b, $link);
        }
        if (in_array($status, ['completed', 'no_show'], true)) {
            if ($b->status !== 'confirmed') {
                throw ValidationException::withMessages(['status' => 'Only confirmed bookings can be closed.']);
            }
            $data['status'] = $status;
        }
        DB::transaction(function () use ($b, $data, $status) {
            DB::table('bookings')->where('id', $b->id)->update($data);
            if ($status === 'completed' && $b->practitioner_id) {
                app(Payouts::class)->accrue(DB::table('bookings')->find($b->id));
            }
        });
        $this->audit->record('booking.updated', null, ['booking' => $b->ref, 'status' => $status], $staff);
        if ($status === 'completed' && $b->status === 'confirmed') {
            app(Reviews::class)->invite($b->order_id ? (int) $b->order_id : null);
        }
        if ($link !== null && $link !== $b->meeting_link) {
            $this->notify($b, 'Your consultation link', 'Join your consultation on '.self::when($b).' here: '.$link);
        }
    }

    private function step(int $resource, CarbonImmutable $start): int
    {
        return (int) (DB::table('availability_rules')->where('resource_key', $resource)->where('weekday', $start->setTimezone(self::TZ)->dayOfWeek)->value('slot_minutes') ?: 30);
    }

    public static function when(object $b): string
    {
        return CarbonImmutable::parse($b->start_utc, 'UTC')->setTimezone(self::TZ)->format('D, d M Y \a\t g:i A').' IST';
    }

    private function notify(object $b, string $title, string $body): void
    {
        app(Notifier::class)->send((int) $b->user_id, 'booking', $title, $body, $b->ref, route('account.bookings'));
    }

    /** Scheduled every 10 minutes: reminders 24 hours and 1 hour before a confirmed consultation. */
    public function sendReminders(): int
    {
        $n = 0;
        foreach ([['reminded_24h_at', 24 * 60, 'tomorrow'], ['reminded_1h_at', 60, 'in about an hour']] as [$col, $minutes, $words]) {
            $due = DB::table('bookings')->where('status', 'confirmed')->whereNull($col)
                ->where('start_utc', '>', now())->where('start_utc', '<=', now()->addMinutes($minutes))->get();
            foreach ($due as $b) {
                if (DB::table('bookings')->where('id', $b->id)->whereNull($col)->update([$col => now()])) {
                    $link = $b->meeting_link ? ' Join here: '.$b->meeting_link : '';
                    $this->notify($b, 'Consultation reminder', "Your consultation is $words: ".self::when($b).'.'.$link);
                    $n++;
                }
            }
        }

        return $n;
    }
}
