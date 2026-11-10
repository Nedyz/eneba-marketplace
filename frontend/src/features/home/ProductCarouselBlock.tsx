import { useQuery } from '@tanstack/react-query'
import { useRef } from 'react'
import { getBlockContent, homeQueryKeys, type HomeBlock } from '../../api/home'
import type { ProductSummary } from '../../api/products'
import { ArrowButton } from '../../components/ui/ArrowButton'
import { ProductCard, ProductCardSkeleton } from '../../components/ui/ProductCard'
import { BlockLoadError } from './BlockLoadError'

/** Card width per breakpoint: about 2.3 cards on a phone (the cut-off card says "swipe"), 6 on desktop. */
const ITEM_CLASSES = 'w-[42%] shrink-0 snap-start sm:w-[30%] md:w-[calc((100%-3*1rem)/4)] lg:w-[calc((100%-5*1rem)/6)]'

/**
 * Block type "product_carousel": a titled row of store cards. Which products
 * (best sellers, deals, anything a future endpoint returns) is decided by the
 * block's endpoint; the title comes from home_blocks. This component knows
 * neither, so a new carousel needs no frontend change (D4).
 */
export function ProductCarouselBlock({ block }: { block: HomeBlock }) {
  const content = useQuery({
    queryKey: homeQueryKeys.block(block.endpoint),
    queryFn: ({ signal }) => getBlockContent<ProductSummary>(block.endpoint, signal),
  })
  const scroller = useRef<HTMLUListElement>(null)

  // An empty carousel is hidden rather than shown as an empty row.
  if (content.isSuccess && content.data.length === 0) {
    return null
  }

  const headingId = `home-block-${block.id}`
  const scroll = (direction: 1 | -1) => {
    const list = scroller.current
    if (!list) return
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    list.scrollBy({ left: direction * list.clientWidth, behavior: reduceMotion ? 'auto' : 'smooth' })
  }

  return (
    <section aria-labelledby={block.title ? headingId : undefined} className="flex flex-col gap-4">
      <div className="flex items-end justify-between gap-4">
        {block.title && (
          <h2 id={headingId} className="text-2xl font-extrabold">
            {block.title}
          </h2>
        )}
        {content.isSuccess && (
          // Swiping or Tab already scrolls; the buttons are for mouse users,
          // who cannot scroll sideways with a normal wheel.
          <div className="ml-auto hidden gap-2 md:flex">
            <ArrowButton direction="previous" onClick={() => scroll(-1)} />
            <ArrowButton direction="next" onClick={() => scroll(1)} />
          </div>
        )}
      </div>

      {content.isError ? (
        <BlockLoadError what={block.title ?? 'these games'} onRetry={() => content.refetch()} />
      ) : (
        <ul
          ref={scroller}
          // relative: the cards' sr-only labels are position:absolute, and an
          // overflow container only clips absolute descendants it positions.
          // Without it, off-screen cards' labels widened the whole page.
          className="relative flex snap-x snap-mandatory gap-4 overflow-x-auto pb-2 [scrollbar-width:thin]"
        >
          {content.isPending
            ? Array.from({ length: 6 }, (_, i) => (
                <li key={i} className={ITEM_CLASSES}>
                  <ProductCardSkeleton />
                </li>
              ))
            : content.data.map((product) => (
                <li key={product.id} className={ITEM_CLASSES}>
                  <ProductCard product={product} headingLevel="h3" />
                </li>
              ))}
        </ul>
      )}
    </section>
  )
}
