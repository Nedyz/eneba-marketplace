<?php

namespace App\Services\Catalog;

/**
 * Validated store filters, passed from the HTTP layer to ProductQuery.
 * A plain value object (like a C# record), so the service does not depend on
 * the Request class and can be reused by search (day 3).
 */
final readonly class ProductFilters
{
    /**
     * @param  list<string>  $platforms  platform slugs (OR)
     * @param  list<string>  $regions  region slugs (OR)
     */
    public function __construct(
        public array $platforms = [],
        public array $regions = [],
        public ?int $priceMinCents = null,
        public ?int $priceMaxCents = null,
        public ProductSort $sort = ProductSort::Newest,
    ) {}
}
