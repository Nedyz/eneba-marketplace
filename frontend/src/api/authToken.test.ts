import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { CART_STORAGE_KEY } from '../features/cart/cartStorage'

/**
 * The store reads localStorage when the module loads, so each test gets a
 * fresh in-memory window and a fresh copy of the module (vi.resetModules).
 * Vitest runs in Node, which has no window.
 */
class MemoryStorage {
  private items = new Map<string, string>()
  getItem(key: string) {
    return this.items.get(key) ?? null
  }
  setItem(key: string, value: string) {
    this.items.set(key, value)
  }
  removeItem(key: string) {
    this.items.delete(key)
  }
}

let storage: MemoryStorage
let storageListener: ((event: Partial<StorageEvent>) => void) | undefined

beforeEach(() => {
  storage = new MemoryStorage()
  storageListener = undefined
  vi.stubGlobal('window', {
    localStorage: storage,
    addEventListener: (type: string, listener: (event: Partial<StorageEvent>) => void) => {
      if (type === 'storage') storageListener = listener
    },
  })
  vi.resetModules()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

const TOKEN = '12|AbCdEf0123456789'

async function loadStore() {
  return await import('./authToken')
}

describe('authToken store', () => {
  it('starts with the stored token', async () => {
    storage.setItem('intersheba.auth.token', TOKEN)
    const { getAuthToken } = await loadStore()
    expect(getAuthToken()).toBe(TOKEN)
  })

  it.each([
    ['nothing stored', null],
    ['an empty string', ''],
    ['no id', '|abc'],
    ['no secret', '12|'],
    ['a JSON value', '{"token":"12|abc"}'],
    ['spaces', '12|abc def'],
    ['a header injection attempt', '12|abc\r\nX-Evil: 1'],
  ])('treats %s as signed out', async (_, raw) => {
    if (raw !== null) storage.setItem('intersheba.auth.token', raw)
    const { getAuthToken } = await loadStore()
    expect(getAuthToken()).toBeNull()
  })

  it('stores and clears the token in localStorage', async () => {
    const { getAuthToken, setAuthToken, clearAuthToken } = await loadStore()

    setAuthToken(TOKEN)
    expect(getAuthToken()).toBe(TOKEN)
    expect(storage.getItem('intersheba.auth.token')).toBe(TOKEN)

    clearAuthToken()
    expect(getAuthToken()).toBeNull()
    expect(storage.getItem('intersheba.auth.token')).toBeNull()
  })

  it('never touches the cart: it survives signing in and out (D8, D33)', async () => {
    const cart = '[{"offer_id":1,"qty":2}]'
    storage.setItem(CART_STORAGE_KEY, cart)
    const { setAuthToken, clearAuthToken } = await loadStore()

    setAuthToken(TOKEN)
    expect(storage.getItem(CART_STORAGE_KEY)).toBe(cart)
    clearAuthToken()
    expect(storage.getItem(CART_STORAGE_KEY)).toBe(cart)
  })

  it('follows sign-in and sign-out in another tab', async () => {
    const { getAuthToken } = await loadStore()

    storageListener?.({ storageArea: storage as unknown as Storage, key: 'intersheba.auth.token', newValue: TOKEN })
    expect(getAuthToken()).toBe(TOKEN)

    storageListener?.({ storageArea: storage as unknown as Storage, key: 'intersheba.auth.token', newValue: null })
    expect(getAuthToken()).toBeNull()
  })

  it('treats localStorage.clear() in another tab as signing out', async () => {
    storage.setItem('intersheba.auth.token', TOKEN)
    const { getAuthToken } = await loadStore()

    storageListener?.({ storageArea: storage as unknown as Storage, key: null, newValue: null })
    expect(getAuthToken()).toBeNull()
  })

  it('ignores other keys changing in another tab', async () => {
    storage.setItem('intersheba.auth.token', TOKEN)
    const { getAuthToken } = await loadStore()

    storageListener?.({ storageArea: storage as unknown as Storage, key: CART_STORAGE_KEY, newValue: '[]' })
    expect(getAuthToken()).toBe(TOKEN)
  })
})
