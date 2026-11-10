import { useSyncExternalStore } from 'react'

/**
 * The Sanctum bearer token, kept in localStorage (D7, D33). Only the token is
 * stored: who it belongs to comes from GET /auth/me.
 *
 * Why localStorage: the API is on another site (onrender.com vs vercel.app),
 * so a cookie would be a third-party cookie that Safari and Firefox block. The
 * cost: any script running on our page can read it, so an XSS bug would leak
 * it. That risk is limited by the 7-day expiry, by logout deleting the token
 * on the server, and kept small by React's escaping and the Content Security
 * Policy (vercel.json). The upside: the browser never attaches it by itself,
 * so other sites cannot make requests with it (no CSRF).
 *
 * Same shape as the cart store (D31): one module-level value, a change event,
 * and the `storage` event so signing in or out in one tab updates the others.
 */

export const AUTH_TOKEN_STORAGE_KEY = 'intersheba.auth.token'

/**
 * A stored value is used only if it looks like a Sanctum token ("{id}|{secret}").
 * Anything else (edited by hand, an old format) counts as signed out rather
 * than being sent to the API.
 */
export function parseToken(raw: string | null): string | null {
  return raw !== null && /^\d+\|[A-Za-z0-9]+$/.test(raw) ? raw : null
}

let token: string | null = readStorage()
const listeners = new Set<() => void>()

function readStorage(): string | null {
  try {
    return parseToken(window.localStorage.getItem(AUTH_TOKEN_STORAGE_KEY))
  } catch {
    // Storage disabled (some private modes): signed out; signing in still works for this tab.
    return null
  }
}

function set(next: string | null): void {
  if (next === token) return
  token = next
  try {
    if (next === null) window.localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY)
    else window.localStorage.setItem(AUTH_TOKEN_STORAGE_KEY, next)
  } catch {
    // Storage unavailable: keep the token in memory for this tab only.
  }
  listeners.forEach((listener) => listener())
}

// Another tab signed in or out (the event never fires in the tab that wrote).
// key === null means localStorage.clear(). The typeof check is for unit tests:
// the API client imports this module, and Vitest runs in Node, without window.
if (typeof window !== 'undefined') {
  window.addEventListener('storage', (event) => {
    if (event.storageArea !== window.localStorage) return
    if (event.key !== AUTH_TOKEN_STORAGE_KEY && event.key !== null) return
    const next = parseToken(event.newValue)
    if (next === token) return
    token = next
    listeners.forEach((listener) => listener())
  })
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

/** The current token, for code outside React (the API client). */
export function getAuthToken(): string | null {
  return token
}

/** The current token; re-renders when it changes in this tab or another. */
export function useAuthToken(): string | null {
  return useSyncExternalStore(subscribe, getAuthToken)
}

export function setAuthToken(next: string): void {
  set(next)
}

export function clearAuthToken(): void {
  set(null)
}
