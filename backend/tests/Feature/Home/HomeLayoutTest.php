<?php

namespace Tests\Feature\Home;

use App\Models\HomeBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/home: the server-driven layout (D4). Which blocks exist, their order
 * and titles come from home_blocks only.
 */
class HomeLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function block(array $attributes): HomeBlock
    {
        return HomeBlock::create($attributes + [
            'type' => 'product_carousel', 'title' => 'Block', 'endpoint' => '/home/deals', 'position' => 1, 'is_active' => true,
        ]);
    }

    public function test_returns_the_block_shape(): void
    {
        $block = $this->block(['type' => 'banner', 'title' => null, 'endpoint' => '/home/banners']);

        $this->getJson('/api/home')
            ->assertOk()
            ->assertExactJson(['blocks' => [
                ['id' => $block->id, 'type' => 'banner', 'title' => null, 'endpoint' => '/home/banners'],
            ]]);
    }

    public function test_blocks_are_ordered_by_position_not_by_creation(): void
    {
        $this->block(['title' => 'Third', 'position' => 3]);
        $this->block(['title' => 'First', 'position' => 1]);
        $this->block(['title' => 'Second', 'position' => 2]);

        $this->getJson('/api/home')->assertJsonPath('blocks.*.title', ['First', 'Second', 'Third']);
    }

    public function test_same_position_falls_back_to_id(): void
    {
        $a = $this->block(['title' => 'A', 'position' => 5]);
        $b = $this->block(['title' => 'B', 'position' => 5]);
        // Created after both, so neither creation order nor a missing tie-break
        // happens to give the right answer.
        $this->block(['title' => 'Before', 'position' => 1]);

        $this->getJson('/api/home')->assertJsonPath('blocks.*.id', fn ($ids) => array_slice($ids, 1) === [$a->id, $b->id]);
    }

    public function test_inactive_blocks_are_left_out(): void
    {
        $this->block(['title' => 'Shown']);
        $this->block(['title' => 'Hidden', 'is_active' => false]);

        $this->getJson('/api/home')->assertJsonPath('blocks.*.title', ['Shown']);
    }

    public function test_unknown_types_are_passed_through(): void
    {
        // The backend does not validate types: the frontend skips the ones it
        // does not know (D4), so a new type can ship before the frontend has it.
        $this->block(['type' => 'video_hero']);

        $this->getJson('/api/home')->assertJsonPath('blocks.0.type', 'video_hero');
    }

    public function test_no_blocks_returns_an_empty_list(): void
    {
        $this->getJson('/api/home')->assertOk()->assertExactJson(['blocks' => []]);
    }
}
