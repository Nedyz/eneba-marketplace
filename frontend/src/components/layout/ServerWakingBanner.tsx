import { useIsFetching, useIsMutating } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

/** Busy periods shorter than this never show the banner. */
const SHOW_AFTER_MS = 3000

/**
 * The API runs on Render's free tier, which sleeps after 15 idle minutes; the
 * first request then takes up to a minute (PLAN.md risk 2). Instead of every
 * page handling that, the shell shows one banner whenever API requests have
 * been running continuously for more than 3 seconds. useIsFetching /
 * useIsMutating count the requests TanStack Query has in flight.
 */
export function ServerWakingBanner() {
  const busy = useIsFetching() + useIsMutating() > 0
  const [slow, setSlow] = useState(false)
  const [wasBusy, setWasBusy] = useState(busy)

  // Reset when a busy period ends, adjusted during render rather than in an
  // effect (React's recommended pattern; avoids an extra render pass).
  if (busy !== wasBusy) {
    setWasBusy(busy)
    if (!busy) setSlow(false)
  }

  // One timer per busy period: depends on `busy`, not on the request count, so
  // requests starting one after another during a cold start do not restart it.
  useEffect(() => {
    if (!busy) return
    const timer = setTimeout(() => setSlow(true), SHOW_AFTER_MS)
    return () => clearTimeout(timer)
  }, [busy])

  if (!busy || !slow) return null

  return (
    <div role="status" className="bg-accent px-5 py-2 text-center text-sm font-extrabold text-black">
      Waking up the server, this can take up to a minute on the free hosting plan…
    </div>
  )
}
