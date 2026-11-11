<?php

namespace App\Services\Checkout;

use App\Http\Resources\CartQuoteResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Cart\CartQuote;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A checkout request that cannot go ahead in the current state: 409 Conflict.
 * Laravel calls render() itself when this exception leaves a controller, like
 * an exception filter in ASP.NET mapping an exception type to a response.
 *
 * The body always has a machine-readable `code` (the frontend switches on it,
 * never on the message) and, depending on the case, a fresh cart `quote` or
 * the `order` in its new state.
 */
final class CheckoutConflict extends RuntimeException
{
    public const OUT_OF_STOCK = 'out_of_stock';

    public const PRICE_CHANGED = 'price_changed';

    public const RESERVATION_EXPIRED = 'reservation_expired';

    public const RESERVATION_LOST = 'reservation_lost';

    public const PAYMENT_IN_PROGRESS = 'payment_in_progress';

    public const ORDER_NOT_PAYABLE = 'order_not_payable';

    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?CartQuote $quote = null,
        public readonly ?Order $order = null,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter([
            'message' => $this->getMessage(),
            'code' => $this->reason,
            'quote' => $this->quote ? (new CartQuoteResource($this->quote))->resolve() : null,
            'order' => $this->order ? (new OrderResource($this->order))->resolve() : null,
        ], fn ($value) => $value !== null), 409);
    }
}
