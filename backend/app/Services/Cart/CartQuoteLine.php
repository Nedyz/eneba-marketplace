<?php

namespace App\Services\Cart;

use App\Models\Offer;

/**
 * One quoted cart line: the buyer's request (offer id, quantity) and what the
 * database says about it at the moment of the quote. Immutable, like a C# record.
 */
final readonly class CartQuoteLine
{
    /**
     * @param  Offer|null  $offer  null when no offer has this id (deleted, or never existed)
     * @param  int  $stock  sellable keys right now; 0 for unavailable lines
     */
    public function __construct(
        public int $offerId,
        public int $qty,
        public CartLineStatus $status,
        public ?Offer $offer,
        public int $stock,
    ) {}

    /**
     * Price per key, only for an offer that is still sold (active). An inactive
     * offer's old price is not something the buyer can pay, so it is not quoted.
     */
    public function unitPriceCents(): ?int
    {
        return $this->status === CartLineStatus::Unavailable ? null : $this->offer?->price_cents;
    }

    /** Only lines that can be bought in full have a total, and only they count in the cart total. */
    public function lineTotalCents(): ?int
    {
        return $this->status === CartLineStatus::Available ? $this->offer->price_cents * $this->qty : null;
    }
}
