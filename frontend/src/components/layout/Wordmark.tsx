import { Link } from 'react-router'

/**
 * Our own text logo (DECISIONS.md D26): "Intersheba", never Eneba's logo, so the
 * site cannot be mistaken for the real store.
 */
export function Wordmark() {
  return (
    <Link to="/" className="flex items-center gap-2 text-2xl font-extrabold tracking-tight" aria-label="Intersheba home">
      <span aria-hidden className="block size-4 bg-accent" />
      intersheba
    </Link>
  )
}
