import { apiGet, apiPost } from './client'

/** Mirrors backend UserResource. */
export interface User {
  id: number
  name: string
  email: string
}

/** POST /auth/register and /auth/login. The token is shown once; only its hash is stored. */
export interface AuthSession {
  token: string
  user: User
}

export interface RegisterInput {
  name: string
  email: string
  password: string
}

export interface LoginInput {
  email: string
  password: string
}

/**
 * Cache key of GET /auth/me, per token: the query cache holds one user per
 * token, so a sign-in as someone else (also from another tab) never shows the
 * previous user, even for one render.
 */
export function meQueryKey(token: string | null) {
  return ['auth', 'me', token] as const
}

export async function register(input: RegisterInput): Promise<AuthSession> {
  return (await apiPost<{ data: AuthSession }>('/auth/register', input)).data
}

export async function login(input: LoginInput): Promise<AuthSession> {
  return (await apiPost<{ data: AuthSession }>('/auth/login', input)).data
}

export async function getMe(signal?: AbortSignal): Promise<User> {
  return (await apiGet<{ data: User }>('/auth/me', { signal })).data
}

/**
 * Revokes `token` on the server. Takes the token explicitly because the app
 * forgets it before this request is sent (signing out is instant on screen).
 */
export async function logout(token: string): Promise<void> {
  await apiPost<null>('/auth/logout', undefined, { headers: { Authorization: `Bearer ${token}` } })
}
