import { useState } from 'react'
import { Link } from 'react-router'
import type { Offer } from '../../api/products'
import { StarIcon } from '../../components/ui/icons'
import { formatPrice } from '../../lib/format'
import { checkAdd } from '../cart/cartStorage'
import { cartActions, useCart } from '../cart/cartStore'
import { stockLabel, stockStatus } from './stock'

/** What the cart snapshot needs from the product (name and link for display). */
interface ProductRef {
  name: string
  slug: string
}

/**
 * The offer comparison. The API already sends offers in display order (in
 * stock first, then price, then offer id: D30), so this component only renders
 * and never re-sorts: one place decides the order, and the first row is
 * exactly the offer behind the store card's price.
 *
 * An ordered list, because the order means something (best offer first).
 */
export function OfferList({ offers, product }: { offers: Offer[]; product: ProductRef }) {
  // One polite live region for the whole list: "Added ... to the cart."
  const [announcement, setAnnouncement] = useState('')

  if (offers.length === 0) {
    return <p className="bg-surface p-6 font-semibold text-text-muted">No seller offers this game yet.</p>
  }

  return (
    <>
      <ol className="flex flex-col gap-3">
        {offers.map((offer, index) => (
          // Sorted in stock first, so only the first row can be the best price.
          <OfferRow
            key={offer.id}
            offer={offer}
            product={product}
            isBest={index === 0 && offer.stock > 0}
            onAdded={setAnnouncement}
          />
        ))}
      </ol>
      <p role="status" className="sr-only">
        {announcement}
      </p>
    </>
  )
}

interface OfferRowProps {
  offer: Offer
  product: ProductRef
  isBest: boolean
  onAdded: (announcement: string) => void
}

function OfferRow({ offer, product, isBest, onAdded }: OfferRowProps) {
  const status = stockStatus(offer.stock)
  const soldOut = status.kind === 'sold-out'
  const hasDiscount = offer.original_price_cents !== null && offer.discount_percent !== null && offer.discount_percent > 0

  return (
    <li
      className={`flex flex-wrap items-center justify-between gap-x-6 gap-y-3 p-4 md:p-5 ${
        // Sold out: no card background and muted text instead of opacity, so the
        // text keeps its contrast (muted on page purple passes AA, D27).
        soldOut ? 'border border-white/15 text-text-muted' : 'bg-surface'
      } ${
        // Inset: an outer ring made this card 2 px wider than the others.
        isBest ? 'ring-2 ring-accent ring-inset' : ''
      }`}
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

      {/* Phone: this group wraps under the seller and spans the row, price on
          the left and button on the right, so the buttons line up from row
          to row. Desktop: price right-aligned next to the button. */}
      <div className="flex w-full items-center justify-between gap-4 md:w-auto md:justify-start md:gap-6">
        <div className="flex flex-col items-start md:items-end">
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
        <AddToCart offer={offer} product={product} onAdded={onAdded} />
      </div>
    </li>
  )
}

const ADD_LABELS = {
  ok: 'Add to cart',
  'sold-out': 'Add to cart',
  'all-in-cart': 'All in cart',
  'cart-full': 'Cart is full',
} as const

/**
 * Adds one key of this offer. Capped at min(10, stock) per offer
 * (checkAdd): more than can be bought would only become a problem line in the
 * cart. The snapshot stores the price shown here, so the cart can tell the
 * buyer if it changes later (D31). It is never used as the price.
 */
function AddToCart({ offer, product, onAdded }: Omit<OfferRowProps, 'isBest'>) {
  const cart = useCart()
  const inCart = cart.find((line) => line.offerId === offer.id)?.qty ?? 0
  const check = checkAdd(cart, offer.id, offer.stock)

  function add() {
    cartActions.add(
      offer.id,
      { productName: product.name, productSlug: product.slug, sellerName: offer.seller.name, priceCents: offer.price_cents },
      offer.stock,
    )
    onAdded(`Added ${product.name} from ${offer.seller.name} to the cart. ${inCart + 1} in cart.`)
  }

  return (
    <div className="flex w-32 flex-col items-stretch gap-1 text-center">
      <button
        type="button"
        disabled={check !== 'ok'}
        onClick={add}
        className="bg-accent px-3 py-2.5 text-button font-extrabold text-black hover:bg-accent-hover disabled:cursor-not-allowed disabled:bg-white/10 disabled:text-text-muted"
      >
        {ADD_LABELS[check]}
        <span className="sr-only"> from {offer.seller.name}</span>
      </button>
      {inCart > 0 && (
        <Link to="/cart" className="text-xs font-semibold underline">
          {inCart} in cart
        </Link>
      )}
    </div>
  )
}
