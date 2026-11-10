import { useEffect, useId, useRef, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { useAuth, useLogout } from '../../features/auth/useAuth'
import { UserIcon } from '../ui/icons'

const linkClass = 'flex items-center gap-2 p-2 text-button font-extrabold'

/**
 * Header account area (D33). Signed out: a "Sign in" link. With a token: the
 * user's name opening a small menu (Orders, Sign out). While the token is
 * being checked, or if the check failed, the button says "Account" so signing
 * out is still possible.
 *
 * A disclosure (button + list of links), not an ARIA "menu": that role
 * promises arrow-key navigation, which two items do not need. Closes on
 * Escape (focus back on the button), on a click outside, and on choosing an item.
 */
export function AccountMenu() {
  const auth = useAuth()
  const logout = useLogout()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const rootRef = useRef<HTMLDivElement>(null)
  const buttonRef = useRef<HTMLButtonElement>(null)
  const menuId = useId()

  useEffect(() => {
    if (!open) return
    function onPointerDown(event: PointerEvent) {
      if (!rootRef.current?.contains(event.target as Node)) setOpen(false)
    }
    function onKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        setOpen(false)
        buttonRef.current?.focus()
      }
    }
    document.addEventListener('pointerdown', onPointerDown)
    document.addEventListener('keydown', onKeyDown)
    return () => {
      document.removeEventListener('pointerdown', onPointerDown)
      document.removeEventListener('keydown', onKeyDown)
    }
  }, [open])

  if (auth.status === 'anonymous') {
    return (
      <Link to="/login" className={linkClass}>
        <UserIcon />
        <span className="hidden md:inline">Sign in</span>
        <span className="sr-only md:hidden">Sign in</span>
      </Link>
    )
  }

  const label = auth.user?.name ?? 'Account'

  function signOut() {
    setOpen(false)
    logout()
    // Leave pages that need sign-in; the cart is kept (D8).
    navigate('/')
  }

  return (
    <div ref={rootRef} className="relative">
      <button
        ref={buttonRef}
        type="button"
        aria-expanded={open}
        aria-controls={menuId}
        onClick={() => setOpen((value) => !value)}
        className={linkClass}
      >
        <UserIcon />
        <span className="hidden max-w-40 truncate md:inline">{label}</span>
        <span className="sr-only md:hidden">{label}</span>
      </button>

      {open && (
        <ul id={menuId} className="absolute right-0 top-full z-30 flex min-w-48 flex-col bg-surface py-2 shadow-lg">
          {auth.user && (
            <li className="truncate px-4 pb-2 text-sm text-text-muted" title={auth.user.email}>
              {auth.user.email}
            </li>
          )}
          <li>
            <Link to="/orders" onClick={() => setOpen(false)} className="block px-4 py-2 font-semibold hover:bg-white/10">
              Your orders
            </Link>
          </li>
          <li>
            <button type="button" onClick={signOut} className="block w-full px-4 py-2 text-left font-semibold hover:bg-white/10">
              Sign out
            </button>
          </li>
        </ul>
      )}
    </div>
  )
}
