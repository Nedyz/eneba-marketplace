<?php

namespace App\Services\Home;

use App\Models\HomeBlock;
use Illuminate\Database\Eloquent\Collection;

/**
 * The homepage layout (D4): which blocks exist, their order and titles all come
 * from `home_blocks`. The frontend only maps `type` to a component and fetches
 * `endpoint`, so reordering, renaming or hiding a block is a database change.
 */
class HomeLayout
{
    /** @return Collection<int, HomeBlock> */
    public function blocks(): Collection
    {
        return HomeBlock::query()
            ->where('is_active', true)
            ->orderBy('position')
            ->orderBy('id') // two rows with the same position still have a fixed order
            ->get();
    }
}
