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

    /** Longer terms only cost more trigrams to compare; no game title needs more. */
    public const MAX_QUERY_LENGTH = 100;

    public function rules(): array
    {
        return [
            // Laravel's global TrimStrings + ConvertEmptyStringsToNull middleware turn
            // "q=" and "q=   " into null, so an empty search box means "no search".
            'q' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_QUERY_LENGTH],
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
        $search = self::normalizeSearch($this->validated('q'));

        return new ProductFilters(
            platforms: array_values(array_unique($this->validated('platform', []))),
            regions: array_values(array_unique($this->validated('region', []))),
            priceMinCents: $this->has('price_min') ? (int) $this->validated('price_min') : null,
            priceMaxCents: $this->has('price_max') ? (int) $this->validated('price_max') : null,
            // With a search term the best matches come first unless another sort is asked for.
            sort: ProductSort::from($this->validated('sort', ($search !== null ? ProductSort::Relevance : ProductSort::Newest)->value)),
            search: $search,
        );
    }

    /**
     * Collapses inner whitespace ("elden    ring" -> "elden ring"); the ends are
     * already trimmed by middleware. Shared with the suggestions endpoint.
     */
    public static function normalizeSearch(?string $q): ?string
    {
        if ($q === null) {
            return null;
        }
        $q = trim(preg_replace('/\s+/u', ' ', $q));

        return $q === '' ? null : $q;
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
