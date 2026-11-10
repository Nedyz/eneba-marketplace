<?php

namespace App\Services\Cart;

use App\Models\Offer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Prices a cart from the database (D8, D31). The browser sends only offer ids
 * and quantities; every price, stock count and total comes from here, so a
 * price saved in localStorage (or edited by hand) can never reach a total.
 *
 * Read-only: nothing is reserved. Keys are reserved only when an order is
 * created (POST /orders, day 5), which will reuse this class so the cart total
 * and the order total come from the same code.
 */
class CartQuoter
{
    /**
     * @param  list<array{offer_id: int, qty: int}>  $lines  distinct offer ids (validated by CartQuoteRequest)
     * @param  CarbonInterface  $now  passed in so tests can move the clock (same as the catalog)
     */
    public function quote(array $lines, CarbonInterface $now): CartQuote
    {
        // One query for all offers, active or not (an inactive offer must still
        // be reported as unavailable), with stock as a correlated COUNT using the
        // same sellable rule as the store, the product page and checkout. The
        // eager loads add a fixed number of queries, however many lines there are.
        $offers = Offer::query()
            ->whereIn('offers.id', array_column($lines, 'offer_id'))
            ->with(['seller', 'product.platform', 'product.region'])
            ->withCount(['keys as stock' => fn (Builder $keys) => $keys->sellable($now)])
            ->get()
            ->keyBy('id');

        // Lines keep the request's order: the cart decides its own display order.
        return new CartQuote(array_map(
            fn (array $line) => $this->line($line['offer_id'], $line['qty'], $offers->get($line['offer_id'])),
            $lines,
        ));
    }

    private function line(int $offerId, int $qty, ?Offer $offer): CartQuoteLine
    {
        if ($offer === null || ! $offer->is_active) {
            return new CartQuoteLine($offerId, $qty, CartLineStatus::Unavailable, $offer, 0);
        }

        $stock = (int) $offer->stock;
        $status = match (true) {
            $stock === 0 => CartLineStatus::SoldOut,
            $stock < $qty => CartLineStatus::InsufficientStock,
            default => CartLineStatus::Available,
        };

        return new CartQuoteLine($offerId, $qty, $status, $offer, $stock);
    }
}
