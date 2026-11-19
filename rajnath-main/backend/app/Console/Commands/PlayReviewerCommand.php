<?php

namespace App\Console\Commands;

use App\Domain\Identity\PlayReviewer;
use Illuminate\Console\Command;

/**
 * Google Play review login (same as Admin › Mobile app › Google Play review login).
 *
 *   php artisan arj:play-reviewer              # turn on (creates the account) and print the email and code
 *   php artisan arj:play-reviewer --new-code   # turn on with a new code
 *   php artisan arj:play-reviewer --off        # turn off and sign the reviewer out
 */
class PlayReviewerCommand extends Command
{
    protected $signature = 'arj:play-reviewer {--new-code : Make a new code} {--off : Turn the review login off}';

    protected $description = 'Set up the Google Play review login and print its email and code';

    public function handle(PlayReviewer $reviewer): int
    {
        if ($this->option('off')) {
            $reviewer->disable();
            $this->info('Google Play review login is off.');

            return self::SUCCESS;
        }
        $login = $reviewer->setUp((bool) $this->option('new-code'));
        $this->info('Google Play review login is on. Put these in Play Console › App content › App access:');
        $this->line('  Username (email): '.$login['email']);
        $this->line('  Password (code):  '.$login['code']);
        $this->line('  Instructions: Open the app › Account › Sign in. Enter the email, tap Send code, then enter the 6-digit code. No email or SMS is needed.');

        return self::SUCCESS;
    }
}
