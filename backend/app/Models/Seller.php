<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'rating'])]
class Seller extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        // decimal:2 keeps the exact value as a string ("4.85") instead of a float.
        return ['rating' => 'decimal:2'];
    }

    /** @return HasMany<Offer, $this> */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
