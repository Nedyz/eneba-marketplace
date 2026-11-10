/**
 * A block that failed to load says so in its own place, with a retry: one
 * failing block (e.g. its service is down) never takes the homepage with it.
 */
export function BlockLoadError({ what, onRetry }: { what: string; onRetry: () => void }) {
  return (
    <div role="alert" className="flex flex-wrap items-center gap-4 bg-bg-deep px-5 py-4">
      <p className="text-text-soft">Could not load {what}.</p>
      <button type="button" onClick={onRetry} className="font-extrabold underline">
        Try again
      </button>
    </div>
  )
}
