import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { type KeyboardEvent, useEffect, useId, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { getSuggestions, productQueryKeys, type ProductSummary } from '../../api/products'
import { Price } from '../../components/ui/Price'
import { ProductCover } from '../../components/ui/ProductCover'
import { CloseIcon, SearchIcon } from '../../components/ui/icons'
import { useDebouncedValue } from '../../lib/useDebouncedValue'
import { normalizeQuery, storeSearchUrl } from '../store/storeParams'

/** Pause in typing before suggestions are requested (see useDebouncedValue). */
const DEBOUNCE_MS = 250
/** The backend rejects shorter terms (SearchSuggestionsRequest, D28). */
const MIN_TERM_LENGTH = 2

type Option = { kind: 'product'; product: ProductSummary } | { kind: 'all' }

/**
 * Header search with suggestions (D6, D28, D29). WAI-ARIA combobox pattern:
 * the input keeps focus, arrow keys move a highlight through the listbox
 * (aria-activedescendant), Enter picks the highlighted option or, with none
 * highlighted, searches the store. The last option is "See all results".
 *
 * Three layers keep requests low and results correct:
 * 1. Debounce: the query key only changes once typing pauses.
 * 2. Cache: each term is its own cache entry, so retyping a term is instant.
 * 3. Keys + abort: the dropdown only reads the current key's data, so a slow
 *    response for an older term can never replace newer results; requests no
 *    longer needed are aborted through the AbortSignal.
 */
export function SearchAutocomplete({ autoFocus = false, onNavigate }: { autoFocus?: boolean; onNavigate?: () => void }) {
  const navigate = useNavigate()
  const location = useLocation()
  const id = useId() // rendered twice (desktop and mobile): ids must be unique
  const inputRef = useRef<HTMLInputElement>(null)

  // On /store the box shows the active search; elsewhere it is empty.
  const urlQuery = location.pathname === '/store' ? normalizeQuery(new URLSearchParams(location.search).get('q')) : null
  const [value, setValue] = useState(urlQuery ?? '')
  // Follow the URL (Back/Forward, "Clear search"), adjusted during render.
  // Typing never writes to the URL; only submitting does.
  const [syncedQuery, setSyncedQuery] = useState(urlQuery)
  if (urlQuery !== syncedQuery) {
    setSyncedQuery(urlQuery)
    setValue(urlQuery ?? '')
  }

  const [open, setOpen] = useState(false)
  const [activeIndex, setActiveIndex] = useState(-1)

  const liveTerm = normalizeQuery(value)
  const debouncedTerm = useDebouncedValue(liveTerm ?? '', DEBOUNCE_MS)

  const suggestions = useQuery({
    queryKey: productQueryKeys.suggestions(debouncedTerm),
    queryFn: ({ signal }) => getSuggestions(debouncedTerm, signal),
    // `open`: no request when the box is filled from the URL but not in use.
    enabled: open && debouncedTerm.length >= MIN_TERM_LENGTH,
    // While a new term loads, keep showing the previous rows (dimmed) instead
    // of flickering to empty on every pause in typing.
    placeholderData: keepPreviousData,
    staleTime: 60_000,
  })

  // Visibility is decided by the LIVE input, not by whether there is data:
  // keepPreviousData still returns old rows when the query is disabled (term
  // under 2 characters), and during the debounce delay the query still holds
  // the previous term's rows. Deleting quickly down to one character must
  // close the list at once.
  const liveTermLongEnough = liveTerm !== null && liveTerm.length >= MIN_TERM_LENGTH
  const showList = open && liveTermLongEnough

  const rows = suggestions.data ?? []
  // Rows belong to an older term: still typing, or the next response pending.
  const stale = debouncedTerm !== liveTerm || suggestions.isPlaceholderData
  const noResults = suggestions.isSuccess && !stale && rows.length === 0
  const options: Option[] = noResults
    ? []
    : [...rows.map((product): Option => ({ kind: 'product', product })), { kind: 'all' }]

  const optionId = (index: number) => `${id}-option-${index}`
  const listboxId = `${id}-listbox`
  const active = showList && activeIndex >= 0 && activeIndex < options.length ? activeIndex : -1

  // Keep the highlighted option visible when the list scrolls (small screens).
  useEffect(() => {
    if (active >= 0) document.getElementById(`${id}-option-${active}`)?.scrollIntoView({ block: 'nearest' })
  }, [active, id])

  function close() {
    setOpen(false)
    setActiveIndex(-1)
  }

  function choose(option: Option | undefined) {
    if (option?.kind === 'product') {
      navigate(`/product/${option.product.slug}`)
    } else if (liveTerm !== null) {
      // A fresh search: only ?q=, the store's other filters are not kept.
      navigate(storeSearchUrl(liveTerm))
    } else {
      return // empty box: nothing to search for
    }
    close()
    inputRef.current?.blur()
    onNavigate?.()
  }

  function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault() // the caret would jump to the start/end
      if (!showList) {
        setOpen(true)
        return
      }
      if (options.length === 0) return
      const step = event.key === 'ArrowDown' ? 1 : -1
      // From "nothing highlighted" (-1), Down goes to the first, Up to the last.
      setActiveIndex(active === -1 ? (step === 1 ? 0 : options.length - 1) : (active + step + options.length) % options.length)
    } else if (event.key === 'Escape' && showList) {
      event.preventDefault() // the native search input would also clear the text
      close()
    }
  }

  return (
    <form
      role="search"
      onSubmit={(event) => {
        event.preventDefault()
        choose(active >= 0 ? options[active] : undefined)
      }}
      className="relative"
    >
      {/* The input has no outline of its own; the whole box shows the focus ring. */}
      <div className="flex h-12 items-center gap-2.5 border border-white px-2.5 focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-accent md:h-[57px]">
        <SearchIcon className="shrink-0" />
        <label htmlFor={`${id}-input`} className="sr-only">
          Search for games
        </label>
        <input
          ref={inputRef}
          id={`${id}-input`}
          type="search"
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={showList}
          aria-controls={showList ? listboxId : undefined}
          aria-activedescendant={active >= 0 ? optionId(active) : undefined}
          autoComplete="off"
          spellCheck={false}
          autoFocus={autoFocus}
          maxLength={100}
          placeholder="Search for games"
          value={value}
          onChange={(event) => {
            setValue(event.target.value)
            setOpen(true)
            setActiveIndex(-1)
          }}
          onFocus={() => setOpen(true)}
          onBlur={close}
          onKeyDown={handleKeyDown}
          // The browser's own clear button (a small blue cross in Chrome and
          // Safari) is hidden for the themed one below. type="search" stays:
          // phones show a Search key, and Escape still clears.
          className="min-w-0 flex-1 bg-transparent text-base font-extrabold outline-none placeholder:text-text md:text-[19px] [&::-webkit-search-cancel-button]:appearance-none"
        />
        {value !== '' && (
          <button
            type="button"
            aria-label="Clear search text"
            // Keeps focus in the input: its blur would close the list first.
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => {
              setValue('')
              setActiveIndex(-1)
              inputRef.current?.focus()
            }}
            className="shrink-0 p-1 text-text-soft hover:text-text"
          >
            <CloseIcon className="size-5" />
          </button>
        )}
      </div>

      {showList && (
        <div className="absolute inset-x-0 top-full z-30 mt-1 bg-surface shadow-2xl shadow-black/50">
          {/* Announces the outcome to screen readers without moving focus. */}
          <p role="status" className="sr-only">
            {noResults ? `No games found for ${liveTerm}` : stale ? '' : `${rows.length} suggestions`}
          </p>

          {noResults ? (
            <p className="px-4 py-4 text-sm text-text-soft">
              No games found for “<span className="font-bold text-text">{liveTerm}</span>”
            </p>
          ) : (
            <>
              {rows.length === 0 && (suggestions.isFetching || stale) && (
                <p className="px-4 pt-3 text-sm text-text-muted">Searching…</p>
              )}
              {suggestions.isError && rows.length === 0 && (
                <p className="px-4 pt-3 text-sm text-text-muted">Suggestions are unavailable right now.</p>
              )}
              <ul
                id={listboxId}
                role="listbox"
                aria-label="Search suggestions"
                className={`max-h-[70vh] overflow-y-auto py-1 transition-opacity ${stale ? 'opacity-60' : ''}`}
              >
                {options.map((option, index) => (
                  <li
                    key={option.kind === 'product' ? option.product.id : 'all'}
                    id={optionId(index)}
                    role="option"
                    aria-selected={index === active}
                    // preventDefault keeps focus in the input, so its blur does
                    // not close the list before the click lands.
                    onMouseDown={(event) => event.preventDefault()}
                    onClick={() => choose(option)}
                    onMouseMove={() => index !== active && setActiveIndex(index)}
                    className={`cursor-pointer ${index === active ? 'bg-white/10' : ''} ${
                      option.kind === 'all' ? 'border-t border-white/10' : ''
                    }`}
                  >
                    {option.kind === 'product' ? (
                      <SuggestionRow product={option.product} />
                    ) : (
                      <span className="block px-4 py-3 text-sm font-extrabold">
                        See all results for “{liveTerm}” <span aria-hidden>→</span>
                      </span>
                    )}
                  </li>
                ))}
              </ul>
            </>
          )}
        </div>
      )}
    </form>
  )
}

function SuggestionRow({ product }: { product: ProductSummary }) {
  return (
    <span className="flex items-center gap-3 px-4 py-2">
      <ProductCover platform={product.platform} imageUrl={product.image_url} size="sm" />
      <span className="min-w-0 flex-1">
        {/* Up to three lines, not one: editions share a name, and on a phone one
            line cut "The Legend of Zelda: Tears of the Kingdom" to "The Legend
            of…". Next to a discounted price it needs all three at 375 px. */}
        <span className="line-clamp-3 leading-snug font-bold">{product.name}</span>
        <span className="block text-xs text-text-soft">
          {product.platform.name} · {product.region.name}
        </span>
      </span>
      <span className="shrink-0 text-right">
        <Price price={product.price} size="sm" />
      </span>
    </span>
  )
}
