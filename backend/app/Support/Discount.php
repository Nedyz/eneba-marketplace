<?php

namespace App\Support;

/**
 * Discount badge value, shared by the store card (from price) and the product
 * page's offer rows, so the two can never round differently.
 */
final class Discount
{
    /**
     * Whole percent, rounded DOWN so the badge never promises more than the real
     * discount (€19.99 instead of €25.00 is 20.04% -> "-20%"). Null when there is
     * no original price. The database guarantees original > price (D22).
     */
    public static function percent(int $priceCents, ?int $originalCents): ?int
    {
        if ($originalCents === null) {
            return null;
        }

        return intdiv(($originalCents - $priceCents) * 100, $originalCents);
    }
}
