<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BirthProfile;
use App\Models\Enquiry;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('customers.view');
        $q = trim((string) $request->query('q', ''));
        $channel = in_array($request->query('channel'), ['web', 'app', 'both'], true) ? $request->query('channel') : null;
        $appIds = fn () => DB::table('personal_access_tokens')->where('tokenable_type', User::class)->select('tokenable_id');
        $list = User::where('kind', User::KIND_CUSTOMER)
            ->when($channel === 'web' || $channel === 'app', fn ($w) => $w->where('signup_channel', $channel))
            ->when($channel === 'both', fn ($w) => $w->where('signup_channel', 'web')->whereIn('id', $appIds()))
            ->when($q !== '', function ($w) use ($q) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $w->where(fn ($x) => $x->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone_e164', 'like', $like));
            })->latest()->paginate(30)->withQueryString();

        $c = fn () => User::where('kind', User::KIND_CUSTOMER);
        $ist = now('Asia/Kolkata');
        $days = collect(range(29, 0))->map(fn ($i) => $ist->copy()->subDays($i)->toDateString());
        $daily = $c()->where('created_at', '>=', $ist->copy()->subDays(29)->startOfDay()->utc())
            ->selectRaw("DATE(DATE_ADD(created_at, INTERVAL 330 MINUTE)) as d, sum(signup_channel = 'app') as app, sum(coalesce(signup_channel, 'web') = 'web') as web")
            ->groupBy('d')->get()->keyBy('d');

        return view('admin.customers.index', ['list' => $list, 'q' => $q, 'channel' => $channel,
            'appUsers' => DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $list->pluck('id'))->pluck('tokenable_id')->flip(),
            'sum' => [
                'total' => $c()->count(), 'web' => $c()->where('signup_channel', 'web')->count(), 'app' => $c()->where('signup_channel', 'app')->count(),
                'app_now' => $c()->whereIn('id', $appIds())->count(),
                'today' => $c()->where('created_at', '>=', $ist->copy()->startOfDay()->utc())->count(),
                'week' => $c()->where('created_at', '>=', $ist->copy()->subDays(6)->startOfDay()->utc())->count(),
                'month' => $c()->where('created_at', '>=', $ist->copy()->subDays(29)->startOfDay()->utc())->count(),
                'active7' => $c()->where('last_login_at', '>=', now()->subDays(7))->count(),
            ],
            'daily' => $days->map(fn ($d) => [$d, (int) ($daily[$d]->web ?? 0), (int) ($daily[$d]->app ?? 0)])->all(),
        ]);
    }

    public function show(User $customer, AuditLogger $audit): View
    {
        $this->authorize('customers.view');
        abort_unless($customer->kind === User::KIND_CUSTOMER, 404);
        $audit->record('customer.viewed_by_staff', $customer);

        return view('admin.customers.show', [
            'c' => $customer,
            'profiles' => BirthProfile::where('user_id', $customer->id)->count(),
            'enquiries' => Enquiry::where('user_id', $customer->id)->latest()->limit(10)->get(),
            'devices' => $customer->tokens()->count() + DB::table('sessions')->where('user_id', $customer->id)->count(),
        ]);
    }

    public function setStatus(Request $request, User $customer, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('customers.restrict');
        abort_unless($customer->kind === User::KIND_CUSTOMER, 404);
        $data = $request->validate(['status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])], 'reason' => ['required', 'string', 'max:300']]);
        $customer->forceFill(['status' => $data['status']])->save();
        if ($data['status'] === User::STATUS_SUSPENDED) {
            DB::table('sessions')->where('user_id', $customer->id)->delete();
            $customer->tokens()->delete();
        }
        $audit->record('customer.status_changed', $customer, ['status' => $data['status'], 'reason' => $data['reason']]);

        return back()->with('status', 'Customer '.($data['status'] === 'active' ? 'reactivated' : 'suspended and signed out everywhere').'.');
    }
}
