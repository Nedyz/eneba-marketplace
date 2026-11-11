<?php

namespace App\Services\Checkout;

use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The card was declined: 402 Payment Required. The order is `failed` and its
 * keys are released; the cart in the browser is untouched, so the buyer can
 * start again in one click (D10, D34).
 */
final class PaymentDeclined extends RuntimeException
{
    public function __construct(public readonly Order $order)
    {
        parent::__construct('Your card was declined. Nothing was charged.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'card_declined',
            'order' => (new OrderResource($this->order))->resolve(),
        ], 402);
    }
}
