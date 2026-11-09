<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One block of the server-driven homepage (D4).
 */
#[Fillable(['type', 'title', 'endpoint', 'position', 'is_active'])]
class HomeBlock extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer', 'is_active' => 'boolean'];
    }
}
