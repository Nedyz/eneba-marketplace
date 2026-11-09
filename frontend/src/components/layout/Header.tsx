import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { filterOptionsQueryKey, getFilterOptions } from '../../api/catalog'
import { CartIcon, SearchIcon, UserIcon } from '../ui/icons'
import { PageContainer } from './PageContainer'
import { Wordmark } from './Wordmark'

/**
 * Site header, modeled on eneba.com's layout:
 * - desktop: wordmark, wide outlined search box, cart and sign-in on one row,
 *   then a darker category bar with platform links.
 * - mobile (< md): one 56px row with icons; the search icon opens the search
 *   field in a row below (Eneba does the same with an overlay).
 */
export function Header() {
  const [mobileSearchOpen, setMobileSearchOpen] = useState(false)

  return (
    <header className="sticky top-0 z-20 bg-bg">
      <PageContainer className="flex h-14 items-center gap-6 md:h-[97px]">
        <Wordmark />

        <div className="hidden flex-1 md:block">
          <SearchForm />
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
          {/* Cart (day 4): the count badge appears once the cart exists. */}
          <Link to="/cart" className="p-2" aria-label="Cart">
            <CartIcon />
          </Link>
          {/* Sign in (day 5). */}
          <Link to="/login" className="flex items-center gap-2 p-2 text-button font-extrabold">
            <UserIcon />
            <span className="hidden md:inline">Sign in</span>
            <span className="sr-only md:hidden">Sign in</span>
          </Link>
        </nav>
      </PageContainer>

      {mobileSearchOpen && (
        <div id="mobile-search" className="border-t border-white/10 bg-bg py-3 md:hidden">
          <PageContainer>
            <SearchForm autoFocus />
          </PageContainer>
        </div>
      )}

      <CategoryBar />
    </header>
  )
}

/**
 * Visual only until day 3: suggestions while typing and Enter -> /store?q=...
 * (DECISIONS.md D6). Eneba's style: transparent box, 1px white outline, bold
 * white placeholder, no rounded corners.
 */
function SearchForm({ autoFocus = false }: { autoFocus?: boolean }) {
  return (
    <form
      role="search"
      onSubmit={(event) => event.preventDefault()}
      // The input itself has no outline (it sits inside the bordered box); the whole
      // box shows the yellow focus ring instead, so keyboard focus stays visible.
      className="flex h-12 items-center gap-2.5 border border-white px-2.5 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent md:h-[57px]"
    >
      <SearchIcon className="shrink-0" />
      <label htmlFor={autoFocus ? 'search-mobile' : 'search-desktop'} className="sr-only">
        Search for games
      </label>
      <input
        id={autoFocus ? 'search-mobile' : 'search-desktop'}
        type="search"
        autoFocus={autoFocus}
        placeholder="Search for games"
        className="min-w-0 flex-1 bg-transparent text-base font-extrabold placeholder:text-text outline-none md:text-[19px]"
      />
    </form>
  )
}

/**
 * Category bar: platform links into the store. The list comes from the API
 * (GET /products/filters, the same cached response the store's filter panel
 * will use), so it only shows platforms that have products. Empty while loading.
 */
function CategoryBar() {
  const { data } = useQuery({ queryKey: filterOptionsQueryKey, queryFn: getFilterOptions, staleTime: 5 * 60_000 })

  return (
    <nav aria-label="Categories" className="hidden h-[50px] bg-bar md:block">
      <PageContainer className="flex h-full items-center gap-1 overflow-x-auto">
        <Link to="/store" className="whitespace-nowrap px-2.5 py-2.5 font-semibold text-text-soft hover:text-text">
          All games
        </Link>
        {data?.platforms.map((platform) => (
          <Link
            key={platform.slug}
            to={`/store?platform[]=${encodeURIComponent(platform.slug)}`}
            className="whitespace-nowrap px-2.5 py-2.5 font-semibold text-text-soft hover:text-text"
          >
            {platform.name}
          </Link>
        ))}
      </PageContainer>
    </nav>
  )
}
