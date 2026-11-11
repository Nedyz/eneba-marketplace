import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { filterOptionsQueryKey, getFilterOptions } from '../../api/catalog'
import { cartCount } from '../../features/cart/cartStorage'
import { useCart } from '../../features/cart/cartStore'
import { SearchAutocomplete } from '../../features/search/SearchAutocomplete'
import { CartIcon, SearchIcon } from '../ui/icons'
import { AccountMenu } from './AccountMenu'
import { PageContainer } from './PageContainer'
import { Wordmark } from './Wordmark'

/**
 * Site header, modeled on eneba.com's layout:
 * - desktop: wordmark, wide outlined search box, cart and account on one row,
 *   then a darker category bar with platform links.
 * - mobile (< md): one 56px row with icons; the search icon opens the search
 *   field in a row below (Eneba does the same with an overlay). The category
 *   bar stays, as a row that scrolls sideways: it holds "All games", the only
 *   link to the store in the header.
 */
export function Header() {
  const [mobileSearchOpen, setMobileSearchOpen] = useState(false)

  return (
    <header className="sticky top-0 z-20 bg-bg">
      <PageContainer className="flex h-14 items-center gap-6 md:h-[97px]">
        <Wordmark />

        <div className="hidden flex-1 md:block">
          <SearchAutocomplete />
        </div>

        <nav aria-label="Account" className="ml-auto flex items-center gap-1 md:gap-4">
          <button
            type="button"
            className="p-2 md:hidden"
            aria-label="Search"
            aria-expanded={mobileSearchOpen}
            aria-controls="mobile-search"
            onClick={() => setMobileSearchOpen((open) => !open)}
          >
            <SearchIcon />
          </button>
          <CartLink />
          <AccountMenu />
        </nav>
      </PageContainer>

      {mobileSearchOpen && (
        <div id="mobile-search" className="border-t border-white/10 bg-bg py-3 md:hidden">
          <PageContainer>
            <SearchAutocomplete autoFocus onNavigate={() => setMobileSearchOpen(false)} />
          </PageContainer>
        </div>
      )}

      <CategoryBar />
    </header>
  )
}

/**
 * Cart icon with the number of keys in the cart, read from localStorage (no
 * request). The count is in the link's name for screen readers.
 */
function CartLink() {
  const count = cartCount(useCart())

  return (
    <Link to="/cart" className="relative p-2" aria-label={count === 0 ? 'Cart' : `Cart, ${count} ${count === 1 ? 'item' : 'items'}`}>
      <CartIcon />
      {count > 0 && (
        <span
          aria-hidden
          className="absolute top-0 right-0 flex h-5 min-w-5 items-center justify-center rounded-full bg-accent px-1 text-xs font-extrabold text-black"
        >
          {count > 99 ? '99+' : count}
        </span>
      )}
    </Link>
  )
}

const CATEGORY_LINK = 'shrink-0 whitespace-nowrap px-2.5 py-2.5 text-sm font-semibold text-text-soft hover:text-text md:text-base'

/**
 * Category bar: platform links into the store. The list comes from the API
 * (GET /products/filters, the same cached response the store's filter panel
 * will use), so it only shows platforms that have products. Empty while loading.
 */
function CategoryBar() {
  const { data } = useQuery({ queryKey: filterOptionsQueryKey, queryFn: getFilterOptions, staleTime: 5 * 60_000 })

  return (
    <nav aria-label="Categories" className="h-11 bg-bar md:h-[50px]">
      {/* Fits on one line on desktop. On a phone the row scrolls sideways: the
          right edge fades out, so a link fading away shows there is more
          (on some widths a link would otherwise end exactly at the edge).
          The extra end padding keeps the last link clear of the fade. */}
      <PageContainer className="flex h-full items-center gap-1 overflow-x-auto [scrollbar-width:none] max-md:pr-10 max-md:[mask-image:linear-gradient(to_right,black_calc(100%_-_32px),transparent)]">
        <Link to="/store" className={CATEGORY_LINK}>
          All games
        </Link>
        {data?.platforms.map((platform) => (
          <Link
            key={platform.slug}
            to={`/store?${new URLSearchParams({ platform: platform.slug })}`}
            className={CATEGORY_LINK}
          >
            {platform.name}
          </Link>
        ))}
      </PageContainer>
    </nav>
  )
}
