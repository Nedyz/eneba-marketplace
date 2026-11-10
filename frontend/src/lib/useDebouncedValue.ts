import { useEffect, useState } from 'react'

/**
 * Returns `value`, but only after it has stopped changing for `delayMs`.
 *
 * Every change starts a timer; the effect's cleanup cancels the previous
 * timer first. While the user keeps typing, timers are cancelled before they
 * fire, so the returned value only updates once typing pauses. Typing
 * "witcher" at ~150 ms per key gives one update (and one request), not seven.
 */
export function useDebouncedValue<T>(value: T, delayMs: number): T {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delayMs)
    return () => clearTimeout(timer)
  }, [value, delayMs])

  return debounced
}
