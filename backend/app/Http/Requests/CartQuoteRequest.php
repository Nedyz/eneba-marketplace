<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /cart/quote: only offer ids and quantities (D31). Any other
 * field a client sends (a price, a total) is never read.
 *
 * An offer id that does not exist is NOT a validation error: a cart in
 * localStorage can outlive an offer, and that line is quoted as unavailable.
 */
class CartQuoteRequest extends FormRequest
{
    /** Also enforced by the frontend's cart storage (features/cart/cartStorage.ts). */
    public const MAX_LINES = 50;

    public const MAX_QTY = 10;

    public function rules(): array
    {
        return [
            // `list`: a JSON array, not an object with arbitrary keys.
            'lines' => ['required', 'list', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*' => ['required', 'array'],
            // distinct: the frontend merges duplicates before sending; a duplicate
            // here would make the per-offer stock check ambiguous.
            'lines.*.offer_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QTY],
        ];
    }

    /** @return list<array{offer_id: int, qty: int}> */
    public function lines(): array
    {
        return array_map(
            fn (array $line) => ['offer_id' => (int) $line['offer_id'], 'qty' => (int) $line['qty']],
            $this->validated('lines'),
        );
    }
}
