<?php

namespace App\Http\Requests;

/**
 * POST /orders: the cart's lines (same rules as the quote, D31) plus the
 * total the buyer was shown. If the server's total differs, nothing is
 * created and the answer is 409 price_changed (D31): nobody is charged an
 * amount they did not see.
 */
class PlaceOrderRequest extends CartQuoteRequest
{
    public function rules(): array
    {
        return parent::rules() + [
            // orders.total_cents is a PostgreSQL integer.
            'expected_total_cents' => ['required', 'integer', 'min:0', 'max:2147483647'],
        ];
    }

    public function expectedTotalCents(): int
    {
        return (int) $this->validated('expected_total_cents');
    }
}
