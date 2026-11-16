<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Puja\PujaRequests;
use App\Http\Controllers\Controller;
use App\Models\PujaRequest;
use App\Support\Logging\Redactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Puja on request" for the app, the same form as the website's /puja: online puja and chadhava are not sold
 * online; the visitor sends their requirements and the team calls back to fix the date, place and dakshina.
 */
class PujaRequestApiController extends Controller
{
    public function __construct(private PujaRequests $requests) {}

    /** Everything the app needs to draw the form: puja list, choices and texts. */
    public function form(): JsonResponse
    {
        $opts = fn (array $map) => collect($map)->map(fn ($label, $value) => ['value' => $value, 'label' => __($label)])->values()->all();

        return response()->json(['data' => [
            'open' => $this->requests->open(),
            'intro' => __($this->requests->intro()),
            'types' => collect($this->requests->types())->map(fn ($t) => ['value' => $t, 'label' => __($t)])->all(),
            'modes' => $opts(PujaRequest::MODES),
            'times' => $opts(PujaRequest::TIMES),
            'samagri' => $opts(PujaRequest::SAMAGRI),
            'languages' => collect(PujaRequest::LANGUAGES)->map(fn ($l) => ['value' => $l, 'label' => __($l)])->all(),
            'steps' => array_map(fn ($s) => __($s), [
                'Send this form. Nothing is charged online.', 'Our team calls you within one working day.',
                'Acharya Ji suggests the vidhi and a shubh muhurat.', 'We confirm the date, place and dakshina with you.',
            ]),
        ]]);
    }

    /** Sends a request. Open to visitors; a customer token links it to the account so it shows in My puja requests. */
    public function store(Request $request): JsonResponse
    {
        abort_unless($this->requests->open(), 404, 'Puja requests are paused for a few days. Please call or WhatsApp us.');
        $rules = PujaRequests::rules();
        $rules['puja_type'][] = Rule::in([...$this->requests->types(), 'other']);
        $data = $request->validate($rules, [
            'consent.accepted' => 'Please agree to be contacted about your request.',
            'puja_other.required_if' => 'Please write the name of the puja you need.',
            'address.required_if' => 'Please write the address or city for a puja at home.',
        ]);
        $phone = PujaRequests::phone($data['phone']);
        if (! $phone) {
            return response()->json(['message' => 'Invalid phone', 'errors' => ['phone' => ['Enter a 10-digit mobile number, or include the country code.']]], 422);
        }
        $user = auth('sanctum')->user();
        $user = $user && $user->tokenCan('customer') ? $user : null;
        $r = $this->requests->create($data, $phone, $user, $request->ip());

        return response()->json(['data' => $this->row($r) + ['phone_masked' => Redactor::mask($r->phone_e164)]], 201);
    }

    /** The signed-in customer's requests, newest first. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => PujaRequest::where('user_id', $request->user()->id)->orderByDesc('id')->limit(100)->get()->map(fn ($r) => $this->row($r))]);
    }

    private function row(PujaRequest $r): array
    {
        return [
            'ref' => $r->ref, 'puja_type' => $r->puja_type, 'status' => $r->status, 'status_label' => __(PujaRequest::STATUSES[$r->status] ?? $r->status),
            'mode' => $r->mode, 'mode_label' => __(PujaRequest::MODES[$r->mode] ?? $r->mode),
            'preferred_date' => $r->preferred_date?->toDateString(),
            'scheduled_at' => $r->scheduled_at?->utc()->toIso8601ZuluString(),
            'priest' => $r->priest, 'quoted_minor' => $r->quoted_minor,
            'meeting_link' => $r->status === 'scheduled' ? $r->meeting_link : null,
            'created_at' => $r->created_at?->utc()->toIso8601ZuluString(),
        ];
    }
}
