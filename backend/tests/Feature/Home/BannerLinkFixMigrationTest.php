<?php

namespace Tests\Feature\Home;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The data migration that fixes banner links in databases seeded before D32
 * (production). Runs the migration's up() again on rows in the old format.
 */
class BannerLinkFixMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function banner(string $link): Banner
    {
        return Banner::create([
            'title' => 'Banner', 'cta_label' => 'Go', 'link_url' => $link, 'position' => 1,
        ]);
    }

    private function runFix(): void
    {
        $migration = require database_path('migrations/2026_11_10_120001_fix_banner_store_links.php');
        $migration->up();
    }

    public function test_rewrites_the_old_seeded_links_and_nothing_else(): void
    {
        $steam = $this->banner('/store?platform[]=steam');
        $nintendo = $this->banner('/store?platform[]=nintendo');
        $search = $this->banner('/store?q=split+fiction');
        $editedByHand = $this->banner('/store?platform[]=steam&sort=price_asc');

        $this->runFix();
        $this->runFix(); // running twice changes nothing more

        $this->assertSame('/store?platform=steam', $steam->fresh()->link_url);
        $this->assertSame('/store?platform=nintendo', $nintendo->fresh()->link_url);
        $this->assertSame('/store?q=split+fiction', $search->fresh()->link_url);
        $this->assertSame('/store?platform[]=steam&sort=price_asc', $editedByHand->fresh()->link_url);
    }
}
