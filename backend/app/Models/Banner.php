<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'subtitle', 'image_url', 'cta_label', 'link_url', 'position', 'is_active', 'starts_at', 'ends_at'])]
class Banner extends Model
{
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }
}
