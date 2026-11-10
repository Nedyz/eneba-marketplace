<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CartQuoteRequest;
use App\Http\Resources\CartQuoteResource;
use App\Services\Cart\CartQuoter;

/**
 * The cart itself lives in the browser (D8). The server only prices it.
 */
class CartController extends Controller
{
    public function __construct(private CartQuoter $quoter) {}

    /**
     * POST /api/cart/quote: current prices, stock and total for the cart's lines
     * (D31). POST because the input is a list; it changes nothing.
     */
    public function quote(CartQuoteRequest $request): CartQuoteResource
    {
        return new CartQuoteResource($this->quoter->quote($request->lines(), now()));
    }
}
