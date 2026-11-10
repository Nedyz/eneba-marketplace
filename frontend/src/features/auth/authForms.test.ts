import { describe, expect, it } from 'vitest'
import { ApiError } from '../../api/client'
import { afterSignInPath, formErrors } from './authForms'

describe('formErrors', () => {
  it('takes the first message of each field from a 422', () => {
    const error = new ApiError(422, {
      message: 'The email field must be a valid email address. (and 1 more error)',
      errors: {
        email: ['The email field must be a valid email address.', 'The email has already been taken.'],
        password: ['The password field must be at least 8 characters.'],
      },
    })

    expect(formErrors(error)).toEqual({
      fields: {
        email: 'The email field must be a valid email address.',
        password: 'The password field must be at least 8 characters.',
      },
      form: null,
    })
  })

  it('shows the wait time the backend sends with a 429', () => {
    const error = new ApiError(429, { message: 'Too many sign-in attempts. Try again in 42 seconds.' })
    expect(formErrors(error)).toEqual({ fields: {}, form: 'Too many sign-in attempts. Try again in 42 seconds.' })
  })

  it('has its own message for a 429 without a body', () => {
    expect(formErrors(new ApiError(429, null)).form).toBe('Too many attempts. Wait a minute and try again.')
  })

  it('treats a request that never got an answer as a connection problem', () => {
    expect(formErrors(new TypeError('Failed to fetch')).form).toMatch(/could not reach the server/i)
  })

  it.each([
    ['a 500', new ApiError(500, { message: 'Server Error' })],
    ['a 422 without field errors', new ApiError(422, { message: 'x' })],
    ['a 422 with malformed errors', new ApiError(422, { errors: { email: 'not a list' } })],
    ['an HTML body', new ApiError(502, '<html>Bad gateway</html>')],
  ])('falls back to a general message for %s', (_, error) => {
    expect(formErrors(error)).toEqual({ fields: {}, form: 'Something went wrong on our side. Please try again.' })
  })
})

describe('afterSignInPath', () => {
  const from = (pathname: unknown, search = '', hash = '') => ({ from: { pathname, search, hash } })

  it('returns to the page that required signing in, with its query and hash', () => {
    expect(afterSignInPath(from('/checkout'))).toBe('/checkout')
    expect(afterSignInPath(from('/orders', '?page=2', '#latest'))).toBe('/orders?page=2#latest')
  })

  it.each([
    ['no state (opened /login directly)', null],
    ['state without from', { other: 1 }],
    ['a from without pathname', { from: { search: '?q=x' } }],
    ['a from that is a string', { from: '/checkout' }],
  ])('goes home for %s', (_, state) => {
    expect(afterSignInPath(state)).toBe('/')
  })

  it.each(['https://evil.example', '//evil.example', '/\\evil.example', 'javascript:alert(1)'])(
    'never leaves the site: %s',
    (pathname) => {
      expect(afterSignInPath(from(pathname))).toBe('/')
    },
  )

  it.each(['/login', '/register'])('does not loop back to %s', (pathname) => {
    expect(afterSignInPath(from(pathname))).toBe('/')
  })
})
