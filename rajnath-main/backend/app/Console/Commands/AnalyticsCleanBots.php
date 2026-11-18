<?php

namespace App\Console\Commands;

use App\Support\Settings\SettingsRepository;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes probable bot page views recorded before page views were confirmed by the browser (the old server-side
 * counting): visits with page views but not a single click or second on screen. A real browser always reports
 * time on screen when the page is left, so these visits came from crawlers or scripts.
 */
class AnalyticsCleanBots extends Command
{
    protected $signature = 'arj:analytics-clean-bots {--dry-run : Only count what would be removed}';

    protected $description = 'Remove old server-counted page views that came from bots (visits with no time on screen and no clicks).';

    public function handle(SettingsRepository $settings): int
    {
        $since = $settings->get('analytics.confirmed_since');
        $before = $since ? CarbonImmutable::parse($since) : now();
        $human = DB::table('analytics_events')->where('channel', 'web')->whereIn('type', ['engage', 'click'])->where('occurred_at', '<', $before)->select('session');
        $q = DB::table('analytics_events')->where('channel', 'web')->where('type', 'pageview')->where('occurred_at', '<', $before)->whereNotIn('session', $human);
        $n = (clone $q)->count();
        if ($this->option('dry-run')) {
            $this->info("$n page views before {$before->toDateTimeString()} UTC look like bots (no time on screen, no clicks).");

            return self::SUCCESS;
        }
        $deleted = 0;
        do {
            $ids = (clone $q)->limit(5000)->pluck('id');
            $deleted += $ids->isEmpty() ? 0 : DB::table('analytics_events')->whereIn('id', $ids)->delete();
        } while ($ids->isNotEmpty());
        $this->info("Removed $deleted bot page views recorded before {$before->toDateTimeString()} UTC.");

        return self::SUCCESS;
    }
}
