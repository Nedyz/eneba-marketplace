<?php

namespace App\Http\Resources;

use App\Services\Cart\CartQuote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Response of POST /cart/quote (D31). Wrapped in "data" like every resource.
 *
 * @property CartQuote $resource
 */
class CartQuoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'lines' => CartQuoteLineResource::collection($this->resource->lines),
            'total_cents' => $this->resource->totalCents(),
            'currency' => 'EUR',
            'can_checkout' => $this->resource->canCheckout(),
        ];
    }
}
