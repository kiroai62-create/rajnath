<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The privacy policy now has to describe visitor statistics, cookies, Google Analytics and ads. This prepares a
 * draft of the English policy with that section (Admin › Legal pages): the owner reads it and presses Publish.
 * Nothing changes on the website until then. Skipped when the policy already has the section or a draft is open.
 */
return new class extends Migration
{
    public function up(): void
    {
        $published = DB::table('legal_documents')->where('kind', 'privacy')->where('locale', 'en')->where('status', 'published')->first();
        if (! $published || str_contains($published->body, '## Analytics, cookies and ads')
            || DB::table('legal_documents')->where('kind', 'privacy')->where('locale', 'en')->where('status', 'draft')->exists()) {
            return;
        }
        $section = <<<'MD'
## Analytics, cookies and ads

- **Our own visitor statistics:** when you open a page, we record the page, the time you spend on it, how far you scroll, the links and buttons you press, the website that sent you, your device type, browser, language and country (looked up on our own server; we do not store your IP address for this). Two first-party cookies (`arj_vid`, `arj_sid`) tell visits apart. If you are signed in, this activity is linked to your account so we can help you and improve our services. We honour "Do Not Track" and "Global Privacy Control". In the app we record which screens are opened and for how long.
- **Calculator history:** details you enter in free calculators are stored encrypted so you can download the result as a PDF and our team can help you with it.
- **In-app calls:** we record when each person joins or leaves a call and connection or camera problems, to fix call quality. Calls are never recorded.
- **Google Analytics and Google AdSense:** if switched on, Google Analytics measures visits, and the free tool pages may show Google ads. They use cookies only after you press "Allow" in the cookie bar; otherwise Google counts without cookies and shows ads that are not personalised. You can change your choice any time with "Cookie settings" at the bottom of the page. See how Google uses data: https://policies.google.com/technologies/partner-sites
- We keep visitor statistics for about 13 months, then delete them.
MD;
        $body = str_contains($published->body, '## Who processes it for us')
            ? str_replace('## Who processes it for us', $section."\n## Who processes it for us", $published->body)
            : rtrim($published->body)."\n\n".$section;
        DB::table('legal_documents')->insert([
            'kind' => 'privacy', 'locale' => 'en', 'version' => 'draft-'.Str::lower(Str::random(8)), 'title' => $published->title, 'body' => $body,
            'change_note' => 'Added "Analytics, cookies and ads": visitor statistics, Google Analytics, AdSense and the cookie choice.',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('legal_documents')->where('kind', 'privacy')->where('status', 'draft')->where('change_note', 'like', 'Added "Analytics, cookies and ads"%')->delete();
    }
};
