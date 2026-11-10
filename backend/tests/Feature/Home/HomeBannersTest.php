<?php

namespace Tests\Feature\Home;

use App\Models\Banner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * GET /api/home/banners: active banners whose [starts_at, ends_at) window
 * contains now, by position.
 */
class HomeBannersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 12:00:00');
    }

    private function banner(array $attributes): Banner
    {
        return Banner::create($attributes + [
            'title' => 'Banner', 'subtitle' => null, 'image_url' => null, 'cta_label' => 'Go',
            'link_url' => '/store', 'position' => 1, 'is_active' => true, 'starts_at' => null, 'ends_at' => null,
        ]);
    }

    private function titles(): array
    {
        return $this->getJson('/api/home/banners')->assertOk()->json('data.*.title');
    }

    public function test_returns_the_banner_shape(): void
    {
        $banner = $this->banner([
            'title' => 'Steam keys for less', 'subtitle' => 'Save on PC games.', 'cta_label' => 'Browse Steam',
            'link_url' => '/store?platform[]=steam', 'starts_at' => now()->subDay(),
        ]);

        // Scheduling and status fields are not part of the response.
        $this->getJson('/api/home/banners')->assertExactJson(['data' => [[
            'id' => $banner->id,
            'title' => 'Steam keys for less',
            'subtitle' => 'Save on PC games.',
            'image_url' => null,
            'cta_label' => 'Browse Steam',
            'link_url' => '/store?platform[]=steam',
        ]]]);
    }

    public function test_ordered_by_position_then_id(): void
    {
        $this->banner(['title' => 'C', 'position' => 2]);
        $this->banner(['title' => 'A', 'position' => 1]);
        $this->banner(['title' => 'B', 'position' => 1]);

        $this->assertSame(['A', 'B', 'C'], $this->titles());
    }

    public function test_inactive_banners_are_left_out(): void
    {
        $this->banner(['title' => 'Shown']);
        $this->banner(['title' => 'Hidden', 'is_active' => false]);

        $this->assertSame(['Shown'], $this->titles());
    }

    public function test_start_bound_is_inclusive(): void
    {
        $this->banner(['title' => 'Starts now', 'starts_at' => now()]);
        $this->banner(['title' => 'Starts in a second', 'starts_at' => now()->addSecond()]);

        $this->assertSame(['Starts now'], $this->titles());
    }

    public function test_end_bound_is_exclusive(): void
    {
        $this->banner(['title' => 'Ends now', 'ends_at' => now()]);
        $this->banner(['title' => 'Ends in a second', 'ends_at' => now()->addSecond()]);

        $this->assertSame(['Ends in a second'], $this->titles());
    }

    public function test_a_banner_appears_and_disappears_with_the_clock(): void
    {
        $this->banner(['title' => 'Black Friday', 'starts_at' => '2026-11-27 00:00:00', 'ends_at' => '2026-11-30 00:00:00']);

        $this->assertSame([], $this->titles());
        Carbon::setTestNow('2026-11-28 12:00:00');
        $this->assertSame(['Black Friday'], $this->titles());
        Carbon::setTestNow('2026-12-01 00:00:00');
        $this->assertSame([], $this->titles());
    }
}
