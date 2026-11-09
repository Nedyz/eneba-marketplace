<?php

namespace App\Http\Requests;

use App\Services\Catalog\ProductFilters;
use App\Services\Catalog\ProductSort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates GET /products. Laravel runs this before the controller (like
 * model validation + [ApiController] returning 400 in ASP.NET Core); invalid
 * input gets a 422 JSON response with per-field errors.
 */
class ProductIndexRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 60;

    /** Prices: integer cents (D12). Upper bound keeps values inside a PostgreSQL integer. */
    private const MAX_PRICE_CENTS = 100_000_000;

    public function rules(): array
    {
        return [
            'platform' => ['sometimes', 'array', 'max:20'],
            'platform.*' => ['string', 'max:100'],
            'region' => ['sometimes', 'array', 'max:20'],
            'region.*' => ['string', 'max:100'],
            'price_min' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_PRICE_CENTS],
            // gte only when price_min is sent: Laravel's gte fails if the other field is missing.
            'price_max' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_PRICE_CENTS,
                ...($this->filled('price_min') ? ['gte:price_min'] : [])],
            'sort' => ['sometimes', Rule::enum(ProductSort::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    public function filters(): ProductFilters
    {
        return new ProductFilters(
            platforms: array_values(array_unique($this->validated('platform', []))),
            regions: array_values(array_unique($this->validated('region', []))),
            priceMinCents: $this->has('price_min') ? (int) $this->validated('price_min') : null,
            priceMaxCents: $this->has('price_max') ? (int) $this->validated('price_max') : null,
            sort: ProductSort::from($this->validated('sort', ProductSort::Newest->value)),
        );
    }

    public function perPage(): int
    {
        return (int) $this->validated('per_page', self::DEFAULT_PER_PAGE);
    }

    public function page(): int
    {
        return (int) $this->validated('page', 1);
    }
}
