/**
 * The one place that talks HTTP to the Laravel API. Feature modules
 * (api/health.ts, later api/products.ts...) call apiGet / apiPost and get typed
 * data back, similar to a typed HttpClient wrapper service in .NET.
 */

const rawBaseUrl = import.meta.env.VITE_API_URL

// Fail loudly at startup instead of sending requests to "undefined/health".
if (!rawBaseUrl) {
  throw new Error('VITE_API_URL is not set. Copy frontend/.env.example to frontend/.env.local.')
}

const API_BASE_URL = rawBaseUrl.replace(/\/+$/, '')

/** Thrown for any non-2xx response. Keeps the status and the parsed JSON body. */
export class ApiError extends Error {
  readonly status: number
  readonly body: unknown

  constructor(status: number, body: unknown) {
    super(`API request failed with status ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.body = body
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  // Only relative paths: the API base URL is the only host we ever call.
  if (!path.startsWith('/')) {
    throw new Error(`API path must start with "/": ${path}`)
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    headers: {
      // Makes Laravel answer errors (404, 422, 500) as JSON instead of HTML.
      Accept: 'application/json',
      ...(init.body ? { 'Content-Type': 'application/json' } : {}),
      ...init.headers,
    },
  })

  // Some responses (e.g. 204 No Content) have no body.
  const text = await response.text()
  const body: unknown = text ? JSON.parse(text) : null

  if (!response.ok) {
    throw new ApiError(response.status, body)
  }

  return body as T
}

export function apiGet<T>(path: string, init?: RequestInit): Promise<T> {
  return request<T>(path, { ...init, method: 'GET' })
}

export function apiPost<T>(path: string, data?: unknown, init?: RequestInit): Promise<T> {
  return request<T>(path, {
    ...init,
    method: 'POST',
    body: data === undefined ? undefined : JSON.stringify(data),
  })
}
