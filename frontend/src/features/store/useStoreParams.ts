import { useCallback, useEffect, useMemo } from 'react'
import { useSearchParams } from 'react-router'
import { applyStoreChange, parseStoreParams, type StoreParams, toSearchParams } from './storeParams'

/**
 * The store's state, read from and written to the URL. There is no useState
 * copy: the URL is the single source of truth, so Back/Forward, reload and
 * shared links all just work (D29).
 */
export function useStoreParams() {
  const [searchParams, setSearchParams] = useSearchParams()

  // useSearchParams returns a new object only when the URL changes, so this
  // parses once per navigation and keeps `params` referentially stable.
  const params = useMemo(() => parseStoreParams(searchParams), [searchParams])

  // Hand-edited or old URLs (?page=abc&platform=steam&page=1...) are rewritten
  // to their canonical form, so the address bar shows what the page shows.
  // replace: true, so the messy URL does not stay as a Back step. This cannot
  // loop: a canonical URL parses back to itself (round-trip tests).
  const canonical = toSearchParams(params).toString()
  useEffect(() => {
    if (canonical !== searchParams.toString()) {
      setSearchParams(new URLSearchParams(canonical), { replace: true })
    }
  }, [canonical, searchParams, setSearchParams])

  /**
   * Applies a change as a new history entry, so Back undoes it. A change that
   * leads to the current URL does nothing: React Router pushes even when the
   * URL is unchanged, which would add a Back step that seems to do nothing.
   */
  const update = useCallback(
    (change: Partial<StoreParams>) => {
      const next = toSearchParams(applyStoreChange(params, change))
      if (next.toString() !== toSearchParams(params).toString()) setSearchParams(next)
    },
    [params, setSearchParams],
  )

  /** "?..." for a link to a changed state (pagination), so links are real hrefs. */
  const searchFor = useCallback(
    (change: Partial<StoreParams>) => `?${toSearchParams(applyStoreChange(params, change))}`,
    [params],
  )

  return { params, update, searchFor }
}
