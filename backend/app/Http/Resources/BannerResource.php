<?php

namespace App\Http\Resources;

use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One banner of the banner block. Scheduling and status fields stay internal:
 * the API only returns what is shown. `link_url` is a frontend route.
 *
 * @mixin Banner
 */
class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_url' => $this->image_url,
            'cta_label' => $this->cta_label,
            'link_url' => $this->link_url,
        ];
    }
}
