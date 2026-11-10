import { Link } from 'react-router'
import { pageWindow } from './pageWindow'

/**
 * Page links are real <a href="?...page=3">, so middle-click and "open in new
 * tab" work. `searchFor(page)` builds each href from the current URL state.
 */
export function Pagination(props: { current: number; last: number; searchFor: (page: number) => string }) {
  const { current, last, searchFor } = props
  // current > last: an old link past the end; the page shows its own message.
  if (last <= 1 || current > last) return null

  const linkClass = 'flex h-10 min-w-10 items-center justify-center px-3 font-extrabold hover:bg-white/10'

  return (
    <nav aria-label="Pagination" className="flex flex-wrap items-center justify-center gap-1">
      {current > 1 && (
        <Link to={{ search: searchFor(current - 1) }} className={linkClass} rel="prev">
          <span aria-hidden>‹</span> Previous
        </Link>
      )}
      {pageWindow(current, last).map((page, index) =>
        page === 'gap' ? (
          <span key={`gap-${index}`} aria-hidden className="px-2 text-text-muted">
            …
          </span>
        ) : page === current ? (
          <span key={page} aria-current="page" className={`${linkClass} bg-accent text-black hover:bg-accent`}>
            {page}
          </span>
        ) : (
          <Link key={page} to={{ search: searchFor(page) }} className={linkClass} aria-label={`Page ${page}`}>
            {page}
          </Link>
        ),
      )}
      {current < last && (
        <Link to={{ search: searchFor(current + 1) }} className={linkClass} rel="next">
          Next <span aria-hidden>›</span>
        </Link>
      )}
    </nav>
  )
}
