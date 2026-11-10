import { ApiError } from '../../api/client'
import { isInternalPath } from '../../lib/paths'

/**
 * Pure rules behind the sign-in and register pages (D33), kept out of the
 * components so they can be unit tested.
 */

export interface FormErrors {
  /** First message per field, from Laravel's 422 body `{ errors: { email: [...] } }`. */
  fields: Partial<Record<string, string>>
  /** A message for the whole form (rate limit, network, server error). */
  form: string | null
}

export const NO_ERRORS: FormErrors = { fields: {}, form: null }

/** Turns a failed register/login request into messages to show. */
export function formErrors(error: unknown): FormErrors {
  if (!(error instanceof ApiError)) {
    // fetch() itself failed: offline, DNS, CORS, the server asleep and timing out.
    return { fields: {}, form: 'Could not reach the server. Check your connection and try again.' }
  }

  const body = isRecord(error.body) ? error.body : {}

  if (error.status === 422 && isRecord(body.errors)) {
    const fields: Partial<Record<string, string>> = {}
    for (const [field, messages] of Object.entries(body.errors)) {
      if (Array.isArray(messages) && typeof messages[0] === 'string') fields[field] = messages[0]
    }
    if (Object.keys(fields).length > 0) return { fields, form: null }
  }

  if (error.status === 429) {
    // The backend says how long to wait ("Try again in 42 seconds.").
    return { fields: {}, form: typeof body.message === 'string' ? body.message : 'Too many attempts. Wait a minute and try again.' }
  }

  return { fields: {}, form: 'Something went wrong on our side. Please try again.' }
}

/**
 * Where to go after signing in. RequireAuth stores the page the user wanted in
 * router state (`{ from: location }`), not in the URL, so nobody can craft a
 * link that redirects elsewhere; the path is still checked, because history
 * state is just data. Never back to /login or /register (that would loop).
 */
export function afterSignInPath(state: unknown): string {
  const from = isRecord(state) && isRecord(state.from) ? state.from : null
  if (!from || typeof from.pathname !== 'string') return '/'

  const { pathname } = from
  if (!isInternalPath(pathname) || pathname === '/login' || pathname === '/register') return '/'

  const search = typeof from.search === 'string' ? from.search : ''
  const hash = typeof from.hash === 'string' ? from.hash : ''
  return pathname + search + hash
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
