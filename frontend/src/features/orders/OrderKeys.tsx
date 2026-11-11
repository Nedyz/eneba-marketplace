import { useState } from 'react'

/**
 * The bought keys of one order line, each with a copy button. A key is shown
 * in a monospace font and selects as a whole on click, so it can also be
 * copied by hand when the clipboard API is not available.
 */
export function OrderKeys({ productName, keys }: { productName: string; keys: string[] }) {
  const [copied, setCopied] = useState<string | null>(null)

  async function copy(key: string) {
    try {
      await navigator.clipboard.writeText(key)
      setCopied(key)
    } catch {
      // Permission denied or no clipboard (non-HTTPS): the key stays selectable.
      setCopied(null)
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm font-semibold">{keys.length === 1 ? 'Your key' : 'Your keys'}</p>
      <ul className="flex flex-col gap-2">
        {keys.map((key) => (
          <li key={key} className="flex flex-wrap items-center gap-3">
            <code className="bg-bg-deep px-3 py-2 font-mono text-lg tracking-wider select-all">{key}</code>
            <button
              type="button"
              onClick={() => copy(key)}
              aria-label={`Copy key for ${productName}`}
              className="border border-white/30 px-3 py-2 text-sm font-semibold hover:border-white/70"
            >
              {copied === key ? 'Copied' : 'Copy'}
            </button>
          </li>
        ))}
      </ul>
      {/* Announces the copy result; the button text changes too, for sighted users. */}
      <p role="status" className="sr-only">
        {copied ? 'Key copied to the clipboard.' : ''}
      </p>
    </div>
  )
}
