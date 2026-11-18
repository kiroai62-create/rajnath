<?php

namespace App\Http\Controllers\Web;

use App\Domain\Booking\Slots;
use App\Domain\Meetings\LiveKit;
use App\Http\Controllers\Controller;
use App\Http\Middleware\TrackAppRequest;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** In-app consultation call (website), the token endpoint and the call log, used by the website and the app. */
class CallController extends Controller
{
    /** What the website and the app report about a call (Admin › Bookings › Call log). */
    public const EVENTS = ['joined', 'reconnecting', 'reconnected', 'rejoined', 'dropped', 'left', 'ended', 'camera_error', 'mic_error', 'failed', 'background', 'foreground'];

    public function __construct(private LiveKit $lk) {}

    private function booking(Request $r, string $ref): object
    {
        $b = DB::table('bookings')->where('ref', $ref)->first();
        abort_unless($b && LiveKit::participant($b, $r->user()), 404);
        if ($r->user()->isStaff() && ! $r->is('api/*')) {
            abort_unless($r->session()->has('staff_mfa_at'), 404);
        }

        return $b;
    }

    public function page(Request $r, string $ref): View
    {
        $b = $this->booking($r, $ref);
        $title = DB::table('offering_variants as v')->join('offering_translations as t', fn ($j) => $j->on('t.offering_id', '=', 'v.offering_id')->where('t.locale', 'en'))->where('v.id', $b->variant_id)->value('t.title');

        return view('public.call', ['b' => $b, 'title' => $title, 'when' => Slots::when($b), 'audioOnly' => $b->mode === 'audio', 'isStaff' => $r->user()->isStaff()]);
    }

    public function token(Request $r, string $ref): JsonResponse
    {
        $b = $this->booking($r, $ref);
        try {
            $t = $this->lk->tokenFor($b, $r->user());
        } catch (ValidationException $e) {
            return response()->json(['error' => ['code' => 'call_not_available', 'message' => collect($e->errors())->flatten()->first()]], 422);
        }
        app(AuditLogger::class)->record('call.joined', null, ['booking' => $b->ref, 'identity' => $t['identity']], $r->user());

        return response()->json($t);
    }

    /** One line of the call log from the website or the app. */
    public function event(Request $r, string $ref): Response
    {
        $b = $this->booking($r, $ref);
        $d = $r->validate(['event' => ['required', Rule::in(self::EVENTS)], 'detail' => ['nullable', 'string', 'max:200']]);
        [, , $role] = LiveKit::participant($b, $r->user());
        $app = $r->is('api/*') && TrackAppRequest::isApp($r);
        DB::table('call_logs')->insert([
            'booking_id' => $b->id, 'user_id' => $r->user()->id, 'role' => $role, 'channel' => $app ? 'app' : 'web',
            'event' => $d['event'], 'detail' => isset($d['detail']) ? mb_substr(strip_tags($d['detail']), 0, 200) : null,
            'device' => mb_substr(($app ? 'App '.$r->header('X-App-Version', '').' · ' : '').(string) $r->userAgent(), 0, 120),
            'created_at' => now(),
        ]);

        return response()->noContent();
    }
}
