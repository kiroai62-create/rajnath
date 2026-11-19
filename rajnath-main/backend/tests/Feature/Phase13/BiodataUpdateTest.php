<?php

namespace Tests\Feature\Phase13;

use Database\Seeders\BiodataUpdateSeeder;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\ShowcaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Owner's bio-data and trophy photos (18 Nov 2026) applied once to the About and Awards pages. */
class BiodataUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogueSeeder::class);
        $this->seed(ShowcaseSeeder::class);
    }

    public function test_corrects_entries_adds_awards_with_photos_and_the_trophy_album(): void
    {
        $this->seed(BiodataUpdateSeeder::class);

        $phd = DB::table('profile_entries')->where('title', 'Ph.D. in Astrology')->first();
        $this->assertStringNotContainsString('1st Division', $phd->detail);
        $this->assertSame('ज्योतिष में पीएच.डी.', $phd->title_hi);
        $this->assertDatabaseHas('profile_entries', ['section' => 'education', 'title' => 'Acharya (Ganit Jyotish) — University Gold Medalist', 'detail' => 'K.S.D.S.U., Darbhanga — topped the university']);
        $this->assertDatabaseMissing('profile_entries', ['title' => 'Jyotish Siromani International Award']);
        $this->assertDatabaseHas('profile_entries', ['title' => 'Jyotish Shiromani Award', 'image_path' => BiodataUpdateSeeder::photo(12)]);
        $this->assertDatabaseHas('profile_entries', ['title' => 'Ayodhya Lifetime Achievement Award', 'year' => '2025', 'image_path' => BiodataUpdateSeeder::photo(9)]);
        $this->assertDatabaseHas('profile_entries', ['title' => 'Top position — Jyotish Shalaka Pariksha', 'year' => '1998']);
        $this->assertDatabaseHas('profile_entries', ['title' => 'Papers at B.H.U. and the Oriental Conference, Tirupati', 'published' => false]);
        foreach (DB::table('profile_entries')->whereNotNull('image_path')->pluck('image_path') as $path) {
            $this->assertFileExists(public_path($path));
        }

        $album = DB::table('gallery_albums')->where('slug', 'award-trophies-at-the-patna-office')->first();
        $this->assertSame('awards', $album->category);
        $this->assertSame(16, DB::table('gallery_items')->where('album_id', $album->id)->count());

        $this->get('/awards')->assertOk()->assertSee('Ayodhya Lifetime Achievement Award')->assertSee('Jyotidhar Samrithi Award')->assertDontSee('1st position');
        $this->get('/about')->assertOk()->assertSee('University Gold Medalist')->assertDontSee('1st Division. Thesis', false);
        $this->get('/awards?lang=hi')->assertOk()->assertSee('अयोध्या लाइफटाइम अचीवमेंट अवार्ड');
    }

    public function test_keeps_owner_edits_and_runs_only_once(): void
    {
        DB::table('profile_entries')->where('title', 'Hind Gaurav Award')->update(['detail' => 'Edited by the owner']);

        $this->seed(BiodataUpdateSeeder::class);
        DB::table('profile_entries')->where('title', 'Ayodhya Lifetime Achievement Award')->delete();
        $this->seed(BiodataUpdateSeeder::class);

        $this->assertDatabaseHas('profile_entries', ['title' => 'Hind Gaurav Award', 'detail' => 'Edited by the owner']);
        $this->assertDatabaseMissing('profile_entries', ['title' => 'Ayodhya Lifetime Achievement Award']);
        $this->assertSame(1, DB::table('gallery_albums')->where('slug', 'award-trophies-at-the-patna-office')->count());
    }
}
