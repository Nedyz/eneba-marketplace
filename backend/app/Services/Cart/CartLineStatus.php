<?php

namespace App\Services\Cart;

/**
 * What the server can say about one cart line right now (D31). A string-backed
 * enum (like a C# enum serialized by name): the value is what the API sends.
 */
enum CartLineStatus: string
{
    /** Enough sellable keys for the whole quantity. Counted in the total. */
    case Available = 'available';

    /** Some sellable keys, but fewer than the quantity. Not counted. */
    case InsufficientStock = 'insufficient_stock';

    /** Active offer with no sellable key (may come back when a reservation expires, D10). Not counted. */
    case SoldOut = 'sold_out';

    /** The offer no longer exists, or the seller withdrew it (is_active = false). Not counted. */
    case Unavailable = 'unavailable';
}
