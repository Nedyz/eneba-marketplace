import { Link } from 'react-router'
import type { ProductSummary } from '../../api/products'
import { Price } from './Price'
import { ProductCover } from './ProductCover'

/**
 * A product in a grid or carousel. Knows nothing about where it is shown
 * (store, homepage blocks on day 4): it takes a product and renders it.
 * The whole card is one link to the product page.
 */
export function ProductCard({ product, headingLevel = 'h2' }: { product: ProductSummary; headingLevel?: 'h2' | 'h3' }) {
  const Heading = headingLevel

  return (
    <Link
      to={`/product/${product.slug}`}
      className="group flex h-full flex-col bg-surface transition-transform duration-150 hover:-translate-y-1"
    >
      <ProductCover name={product.name} platformSlug={product.platform.slug} imageUrl={product.image_url} />
      <div className="flex flex-1 flex-col gap-3 p-4">
        <Heading className="line-clamp-2 font-bold group-hover:underline">{product.name}</Heading>
        <p className="text-xs font-semibold text-text-soft">
          {product.platform.name} · {product.region.name}
        </p>
        <div className="mt-auto">
          <Price price={product.price} />
        </div>
      </div>
    </Link>
  )
}

/** Same footprint as a card, shown while the first page loads. */
export function ProductCardSkeleton() {
  return (
    <div aria-hidden className="flex flex-col bg-surface">
      <div className="aspect-[3/4] w-full animate-pulse bg-white/5" />
      <div className="flex flex-col gap-3 p-4">
        <div className="h-4 w-3/4 animate-pulse bg-white/10" />
        <div className="h-3 w-1/2 animate-pulse bg-white/10" />
        <div className="mt-4 h-6 w-1/3 animate-pulse bg-white/10" />
      </div>
    </div>
  )
}
