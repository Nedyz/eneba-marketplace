import { ApiError, apiGet } from './client'

/** Mirrors the JSON returned by backend HealthController. */
export interface HealthResponse {
  status: 'ok' | 'degraded'
  database: 'ok' | 'error'
  pg_trgm: string | null
  time: string
}

/**
 * The API answers 503 with the same JSON shape when something is wrong.
 * We still want to show that body ("database: error"), so a 503 that carries
 * a health payload is returned as data instead of being thrown.
 */
export async function getHealth(): Promise<HealthResponse> {
  try {
    return await apiGet<HealthResponse>('/health')
  } catch (error) {
    if (error instanceof ApiError && error.status === 503 && error.body) {
      return error.body as HealthResponse
    }
    throw error
  }
}
