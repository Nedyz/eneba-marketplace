<?php

namespace App\Http\Resources;

use App\Models\HomeBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One homepage block (D4). `type` is passed through as stored: the frontend
 * skips types it does not know, so a new type never breaks an older frontend.
 * `endpoint` is a path relative to the API base.
 *
 * @mixin HomeBlock
 */
class HomeBlockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'endpoint' => $this->endpoint,
        ];
    }
}
