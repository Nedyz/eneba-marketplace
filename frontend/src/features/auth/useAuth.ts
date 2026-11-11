import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { type AuthSession, getMe, login, logout, meQueryKey, register, type User } from '../../api/auth'
import { clearAuthToken, getAuthToken, setAuthToken, useAuthToken } from '../../api/authToken'
import { ApiError } from '../../api/client'

/**
 * - `anonymous`: no token.
 * - `loading`: a token, not yet confirmed by GET /auth/me (first load, or the
 *   server waking up).
 * - `authenticated`: the token belongs to `user`.
 * - `error`: a token, but /auth/me failed for another reason than 401 (network,
 *   server down). Not treated as signed out: the token may well be valid.
 *
 * A 401 never shows up here: the API client clears the token on 401, so the
 * state becomes `anonymous`.
 */
export type AuthState =
  | { status: 'anonymous'; user: null }
  | { status: 'loading'; user: null }
  | { status: 'authenticated'; user: User }
  | { status: 'error'; user: null; retry: () => void }

/** Who is signed in. Every component calling this shares one cached /auth/me request. */
export function useAuth(): AuthState {
  const token = useAuthToken()
  const me = useQuery({
    queryKey: meQueryKey(token),
    queryFn: ({ signal }) => getMe(signal),
    enabled: token !== null,
    // The user rarely changes; checking again every 5 minutes (when the tab
    // regains focus or a page mounts) notices an expired or revoked token.
    staleTime: 5 * 60_000,
    // Retrying a 401 cannot help; other failures get the app's usual one retry.
    retry: (failures, error) => !(error instanceof ApiError && error.status === 401) && failures < 1,
  })

  if (token === null) return { status: 'anonymous', user: null }
  if (me.data) return { status: 'authenticated', user: me.data }
  if (me.isError) return { status: 'error', user: null, retry: () => void me.refetch() }
  return { status: 'loading', user: null }
}

/**
 * Sign in or register. On success the user is put in the cache under the new
 * token's key before the token is stored, so the app switches straight to
 * `authenticated` with no extra /auth/me request.
 */
function useSignIn<Input>(request: (input: Input) => Promise<AuthSession>) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: request,
    onSuccess: ({ token, user }) => {
      queryClient.setQueryData(meQueryKey(token), user)
      setAuthToken(token)
    },
  })
}

export const useLogin = () => useSignIn(login)
export const useRegister = () => useSignIn(register)

/**
 * Sign out: forget the token and everything cached for this user at once, then
 * revoke the token on the server in the background. The screen never waits for
 * the server (which may be asleep, PLAN.md risk 2); if the request fails, the token stays
 * valid on the server until it expires (7 days, D18), but this browser no
 * longer has it. The cart is kept (D8, D33).
 */
export function useLogout(): () => void {
  const queryClient = useQueryClient()
  return () => {
    const token = getAuthToken()
    if (token === null) return
    clearAuthToken()
    // Everything user-specific: /auth/me now, orders from day 6 (key ['orders', ...]).
    queryClient.removeQueries({ queryKey: ['auth'] })
    queryClient.removeQueries({ queryKey: ['orders'] })
    logout(token).catch(() => {})
  }
}
