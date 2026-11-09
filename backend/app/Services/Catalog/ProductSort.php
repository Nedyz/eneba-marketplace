<?php

namespace App\Services\Catalog;

/**
 * Store sort orders. A PHP enum, so the request validation, the query builder
 * and the tests share one list of allowed values.
 */
enum ProductSort: string
{
    case Newest = 'newest';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case Name = 'name';
}
