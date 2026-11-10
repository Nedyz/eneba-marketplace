import type { Offer } from '../../api/products'
import { StarIcon } from '../../components/ui/icons'
import { formatPrice } from '../../lib/format'
import { stockLabel, stockStatus } from './stock'

/**
 * The offer comparison. The API already sends offers in display order (in
 * stock first, then price, then offer id: D30), so this component only renders
 * and never re-sorts: one place decides the order, and the first row is
 * exactly the offer behind the store card's price.
 *
 * An ordered list, because the order means something (best offer first).
 */
export function OfferList({ offers }: { offers: Offer[] }) {
  if (offers.length === 0) {
    return <p className="bg-surface p-6 font-semibold text-text-muted">No seller offers this game yet.</p>
  }

  return (
    <ol className="flex flex-col gap-3">
      {offers.map((offer, index) => (
        // Sorted in stock first, so only the first row can be the best price.
        <OfferRow key={offer.id} offer={offer} isBest={index === 0 && offer.stock > 0} />
      ))}
    </ol>
  )
}

function OfferRow({ offer, isBest }: { offer: Offer; isBest: boolean }) {
  const status = stockStatus(offer.stock)
  const soldOut = status.kind === 'sold-out'
  const hasDiscount = offer.original_price_cents !== null && offer.discount_percent !== null && offer.discount_percent > 0

  return (
    <li
      className={`flex flex-wrap items-center justify-between gap-x-6 gap-y-3 p-4 md:p-5 ${
        // Sold out: no card background and muted text instead of opacity, so the
        // text keeps its contrast (muted on page purple passes AA, D27).
        soldOut ? 'border border-white/15 text-text-muted' : 'bg-surface'
      } ${isBest ? 'ring-2 ring-accent' : ''}`}
    >
      <div className="flex min-w-0 flex-col gap-1">
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
          <h3 className="font-extrabold">{offer.seller.name}</h3>
          {isBest && <span className="bg-accent px-2 py-0.5 text-xs font-extrabold text-black">Best price</span>}
        </div>
        <p className="flex items-center gap-3 text-sm">
          <span className="flex items-center gap-1">
            <StarIcon className={`size-4 ${soldOut ? '' : 'text-accent'}`} />
            <span className="sr-only">Seller rating </span>
            {offer.seller.rating.toFixed(2)}
            <span className="sr-only"> out of 5</span>
          </span>
          <span className={`font-semibold ${soldOut ? '' : status.kind === 'low' ? 'text-accent' : 'text-success'}`}>
            {stockLabel(status)}
          </span>
        </p>
      </div>

      {/* The add to cart button goes on the right of the price (cart session). */}
      <div className="flex flex-col items-end">
        {hasDiscount && (
          <p className="flex items-center gap-2 text-xs text-text-muted">
            <s>
              <span className="sr-only">Was </span>
              {formatPrice(offer.original_price_cents!)}
            </s>
            <span className={`font-extrabold ${soldOut ? '' : 'text-discount'}`}>-{offer.discount_percent}%</span>
          </p>
        )}
        <p className="text-price font-extrabold">{formatPrice(offer.price_cents)}</p>
      </div>
    </li>
  )
}
