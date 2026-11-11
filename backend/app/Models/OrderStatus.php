<?php

namespace App\Models;

/**
 * Order lifecycle (PLAN.md "Checkout and key assignment", D10, D34):
 *
 *   pending ──pay──► paying ──► paid
 *      │                 └────► failed      (declined, or keys lost: refunded)
 *      ├── past expires_at ──► expired      (keys released)
 *      └── newer order ──────► cancelled    (keys released)
 *   paying past its hold ────► failed       (interrupted payment, keys released)
 *
 * Stored as the string value (varchar + CHECK constraint), like a C# enum
 * persisted by name.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paying = 'paying';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    /** Keys are held for the order (reserved) only in these two states. */
    public function holdsKeys(): bool
    {
        return $this === self::Pending || $this === self::Paying;
    }
}
