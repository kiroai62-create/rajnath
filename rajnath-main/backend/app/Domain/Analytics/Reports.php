<?php

namespace App\Domain\Analytics;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for Admin › Analytics. Dates are chosen and shown in India time; events are stored
 * in UTC. Every list is a small grouped query over analytics_events or tool_runs.
 */
class Reports
{
    /** India time offset used to group rows by Indian calendar day. */
    private const IST = 'INTERVAL 330 MINUTE';

    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(?string $from = null, ?string $to = null, int $days = 30)
    {
        $tz = 'Asia/Kolkata';
        $this->to = $to ? CarbonImmutable::parse($to, $tz)->endOfDay() : CarbonImmutable::now($tz)->endOfDay();
        $this->from = $from ? CarbonImmutable::parse($from, $tz)->startOfDay() : $this->to->subDays(max(1, $days) - 1)->startOfDay();
        if ($this->from->greaterThan($this->to)) {
            [$this->from, $this->to] = [$this->to->startOfDay(), $this->from->endOfDay()];
        }
    }

    public function events(string|array $type, string $channel = 'web'): Builder
    {
        return DB::table('analytics_events')->whereIn('type', (array) $type)->where('channel', $channel)
            ->whereBetween('occurred_at', [$this->from->utc(), $this->to->utc()]);
    }

    /** @return array<string, int|float> */
    public function websiteTotals(): array
    {
        $pv = $this->events('pageview');
        $sessions = (int) (clone $pv)->distinct()->count('session');
        // Google Analytics 4 definitions: an engaged session lasted 10 seconds or more on screen, or had two or more
        // page views; bounce rate is the share of sessions that were not engaged.
        $perSession = $this->events(['pageview', 'engage'])->select('session')
            ->selectRaw("sum(type = 'pageview') as views, sum(case when type = 'engage' then value else 0 end) as secs")->groupBy('session')->havingRaw('views > 0');
        $engagedSessions = (int) DB::query()->fromSub($perSession, 's')->where(fn ($w) => $w->where('views', '>=', 2)->orWhere('secs', '>=', 10))->count();
        $engaged = (int) $this->events('engage')->sum('value');
        $depth = $this->events('engage')->whereNotNull('depth')->avg('depth');

        return [
            'visitors' => (int) (clone $pv)->distinct()->count('visitor'),
            'new_visitors' => (int) (clone $pv)->where('is_new', true)->count(),
            'sessions' => $sessions,
            'pageviews' => (int) (clone $pv)->count(),
            'signed_in' => (int) (clone $pv)->whereNotNull('user_id')->distinct()->count('user_id'),
            'pages_per_session' => $sessions ? round((clone $pv)->count() / $sessions, 1) : 0,
            'engaged_sessions' => $engagedSessions,
            'engagement_rate' => $sessions ? (int) round($engagedSessions * 100 / $sessions) : 0,
            'bounce_rate' => $sessions ? (int) round(($sessions - $engagedSessions) * 100 / $sessions) : 0,
            'avg_depth' => $depth !== null ? (int) round((float) $depth) : null,
            'avg_seconds' => $sessions ? (int) round($engaged / $sessions) : 0,
            'clicks' => (int) $this->events('click')->count(),
            'live' => (int) DB::table('analytics_events')->where('channel', 'web')->whereIn('type', ['pageview', 'click', 'engage'])
                ->where('occurred_at', '>=', now()->subMinutes(5))->distinct()->count('visitor'),
        ];
    }

    /**
     * Per-day counts for a chart: [[Y-m-d, value, value2], …] over the whole range.
     *
     * @return list<array{0: string, 1: int, 2: int}>
     */
    public function daily(Builder $q, string $countA = 'count(*)', string $countB = 'count(distinct visitor)', string $col = 'occurred_at'): array
    {
        $rows = $q->selectRaw("DATE(DATE_ADD($col, ".self::IST.")) as d, $countA as a, $countB as b")->groupBy('d')->get()->keyBy('d');
        $out = [];
        for ($d = $this->from; $d->lessThanOrEqualTo($this->to); $d = $d->addDay()) {
            $k = $d->toDateString();
            $out[] = [$k, (int) ($rows[$k]->a ?? 0), (int) ($rows[$k]->b ?? 0)];
        }

        return $out;
    }

    /**
     * Top values of a column with their count and unique visitors.
     *
     * @return list<object{key: ?string, n: int, v: int}>
     */
    public function top(Builder $q, string $column, int $limit = 10): array
    {
        return $q->selectRaw("$column as k, count(*) as n, count(distinct visitor) as v")->groupBy('k')->orderByDesc('n')->limit($limit)->get()->all();
    }

    /** @return list<object> Pages where visits start. */
    public function entryPages(int $limit = 10): array
    {
        $first = $this->events('pageview')->selectRaw('min(id) as id')->groupBy('session');

        return DB::table('analytics_events')->whereIn('id', $first)->selectRaw('path as k, count(*) as n, count(distinct visitor) as v')
            ->groupBy('k')->orderByDesc('n')->limit($limit)->get()->all();
    }

    /**
     * Recent visits for the visitor explorer, newest first: who (signed-in name), where from, device, entry page,
     * pages, time on screen.
     */
    public function sessions(array $filters = [], int $perPage = 40): LengthAwarePaginator
    {
        $q = $this->events(['pageview', 'engage', 'click'])
            ->when($filters['signed_in'] ?? false, fn ($w) => $w->whereNotNull('user_id'))
            ->when($filters['country'] ?? null, fn ($w, $c) => $w->where('country', $c))
            ->when($filters['device'] ?? null, fn ($w, $d) => $w->where('device', $d))
            ->when($filters['source'] ?? null, fn ($w, $s) => $s === 'direct' ? $w->whereNull('referrer') : $w->where('referrer', 'like', '%'.addcslashes($s, '%_\\').'%'))
            ->when($filters['visitor'] ?? null, fn ($w, $v) => $w->where('visitor', $v))
            ->select('session')
            ->selectRaw("min(occurred_at) as started, max(occurred_at) as ended, max(visitor) as visitor, max(user_id) as user_id, max(country) as country,
                max(device) as device, max(os) as os, max(browser) as browser, max(referrer) as referrer, max(utm_campaign) as campaign,
                sum(type = 'pageview') as views, sum(type = 'click') as clicks, sum(case when type = 'engage' then value else 0 end) as secs,
                max(case when type = 'engage' then depth end) as depth, max(is_new) as is_new, min(case when type = 'pageview' then id end) as first_id")
            ->groupBy('session')->havingRaw('views > 0')->orderByDesc('started');

        $page = $q->paginate($perPage)->withQueryString();
        $entry = DB::table('analytics_events')->whereIn('id', collect($page->items())->pluck('first_id')->filter())->pluck('path', 'id');
        $names = DB::table('users')->whereIn('id', collect($page->items())->pluck('user_id')->filter())->pluck('name', 'id');
        foreach ($page->items() as $row) {
            $row->entry = $entry[$row->first_id] ?? null;
            $row->name = $row->user_id ? ($names[$row->user_id] ?? null) : null;
        }

        return $page;
    }

    /** @return list<object> Clicked links and buttons. */
    public function topClicks(int $limit = 15): array
    {
        return $this->events('click')->selectRaw('label, target, count(*) as n, count(distinct visitor) as v')
            ->groupBy('label', 'target')->orderByDesc('n')->limit($limit)->get()->all();
    }

    /** @return list<object{tool: string, runs: int, people: int, pdfs: int, web: int, app: int, signed_in: int}> */
    public function toolTotals(): array
    {
        return DB::table('tool_runs')->whereBetween('created_at', [$this->from->utc(), $this->to->utc()])
            ->selectRaw("tool, count(*) as runs, count(distinct coalesce(visitor, concat('u', user_id), id)) as people, sum(pdf_downloads) as pdfs,
                sum(channel = 'web') as web, sum(channel = 'app') as app, sum(user_id is not null) as signed_in")
            ->groupBy('tool')->orderByDesc('runs')->get()->all();
    }

    public function toolRuns(): Builder
    {
        return DB::table('tool_runs')->whereBetween('created_at', [$this->from->utc(), $this->to->utc()]);
    }

    /** @return array<string, int> */
    public function appTotals(): array
    {
        return [
            'opens' => (int) $this->events('app_open', 'app')->count(),
            'devices' => (int) $this->events(['app_open', 'app_call', 'app_screen'], 'app')->distinct()->count('visitor'),
            'signed_in' => (int) $this->events(['app_open', 'app_call', 'app_screen'], 'app')->whereNotNull('user_id')->distinct()->count('user_id'),
            'calls' => (int) $this->events('app_call', 'app')->count(),
            'tool_runs' => (int) $this->toolRuns()->where('channel', 'app')->count(),
            'today' => (int) DB::table('analytics_events')->where('channel', 'app')->where('type', 'app_open')
                ->where('occurred_at', '>=', CarbonImmutable::now('Asia/Kolkata')->startOfDay()->utc())->distinct()->count('visitor'),
        ];
    }

    /** Readable name for an API route used by the app. */
    public static function feature(?string $route): string
    {
        $r = (string) $route;
        $map = [
            'api/v1/tools' => 'Calculators', 'api/v1/panchang' => 'Panchang', 'api/v1/horoscopes' => 'Rashifal', 'api/v1/rashi' => 'Rashifal (sign page)',
            'api/v1/gochar' => 'Grah gochar', 'api/v1/muhurat' => 'Shubh muhurat', 'api/v1/festivals' => 'Festivals', 'api/v1/calendar' => 'Vrat calendar',
            'api/v1/catalogue' => 'Services', 'api/v1/store' => 'Gem store', 'api/v1/blog' => 'Articles', 'api/v1/gallery' => 'Gallery', 'api/v1/reviews' => 'Reviews',
            'api/v1/assistant' => 'AI assistant', 'api/v1/checkout' => 'Checkout', 'api/v1/bookings' => 'Bookings', 'api/v1/orders' => 'Orders',
            'api/v1/reports' => 'Reports', 'api/v1/palmistry' => 'Palmistry', 'api/v1/puja' => 'Puja', 'api/v1/enquiries' => 'Enquiries',
            'api/v1/birth-profiles' => 'Birth profiles', 'api/v1/wallet' => 'Wallet', 'api/v1/site' => 'About & contact', 'api/v1/faq' => 'FAQ',
            'api/v1/navagraha' => 'Nine planets', 'api/v1/practitioners' => 'Astrologers', 'api/v1/profile' => 'About Acharya Ji', 'api/v1/places' => 'Place search',
        ];
        foreach ($map as $prefix => $name) {
            if (str_starts_with($r, $prefix)) {
                return $name;
            }
        }

        return $r !== '' ? $r : 'Other';
    }
}
