<?php

namespace App\Services\Home;

use App\Models\Banner;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Content of the banner block: active banners whose schedule contains $now.
 * A null starts_at / ends_at means no limit on that side. The window is
 * half-open [starts_at, ends_at): a banner ending at midnight is gone at midnight.
 */
class HomeBanners
{
    /** @return Collection<int, Banner> */
    public function current(CarbonInterface $now): Collection
    {
        return Banner::query()
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }
}
