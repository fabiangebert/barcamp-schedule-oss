/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach } from 'vitest'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key) => key
  })
}))

vi.mock('../src/utils/notificationHelpers', () => ({
  showApiError: vi.fn()
}))

import { useAuth } from '../src/composables/useAuth'
import { showApiError } from '../src/utils/notificationHelpers'

describe('useAuth', () => {
  let showNotification
  let store

  beforeEach(() => {
    store = {}
    Object.defineProperty(window, 'localStorage', {
      value: {
        getItem: vi.fn((key) => store[key] ?? null),
        setItem: vi.fn((key, value) => { store[key] = String(value) }),
        removeItem: vi.fn((key) => { delete store[key] }),
        clear: vi.fn(() => { Object.keys(store).forEach(k => delete store[k]) }),
        get length() { return Object.keys(store).length },
        key: vi.fn((i) => Object.keys(store)[i] ?? null)
      },
      writable: true,
      configurable: true
    })
    showNotification = vi.fn()
    vi.clearAllMocks()
  })

  describe('initial state', () => {
    it('is default when no localStorage is set', () => {
      const auth = useAuth(showNotification)
      expect(auth.user.value).toBeNull()
      expect(auth.userName.value).toBeNull()
      expect(auth.token.value).toBeNull()
      expect(auth.isAdmin.value).toBe(false)
      expect(auth.isUser.value).toBe(false)
      expect(auth.isGuest.value).toBe(false)
      expect(auth.isNameCustomized.value).toBe(false)
    })

    it('reads user from localStorage', () => {
      store['user'] = JSON.stringify({ id: 1, email: 'a@b.com', role: 'user' })
      store['userName'] = 'Test User'
      store['token'] = 'abc123'
      store['role'] = 'user'

      const auth = useAuth(showNotification)
      expect(auth.user.value.email).toBe('a@b.com')
      expect(auth.userName.value).toBe('Test User')
      expect(auth.token.value).toBe('abc123')
      expect(auth.isUser.value).toBe(true)
      expect(auth.isAdmin.value).toBe(false)
      expect(auth.isGuest.value).toBe(false)
    })
  })

  describe('computed permissions', () => {
    it('canEdit is true for admin', () => {
      store['role'] = 'admin'
      const auth = useAuth(showNotification)
      expect(auth.canEdit.value).toBe(true)
    })

    it('canEdit is true for user', () => {
      store['role'] = 'user'
      const auth = useAuth(showNotification)
      expect(auth.canEdit.value).toBe(true)
    })

    it('canEdit is false for guest', () => {
      store['role'] = 'guest'
      const auth = useAuth(showNotification)
      expect(auth.canEdit.value).toBe(false)
    })

    it('canSeeDocsOverview is true for all roles', () => {
      store['role'] = 'guest'
      const authGuest = useAuth(showNotification)
      expect(authGuest.canSeeDocsOverview.value).toBe(true)

      store['role'] = 'user'
      const authUser = useAuth(showNotification)
      expect(authUser.canSeeDocsOverview.value).toBe(true)

      store['role'] = 'admin'
      const authAdmin = useAuth(showNotification)
      expect(authAdmin.canSeeDocsOverview.value).toBe(true)
    })
  })

  describe('logout', () => {
    it('clears all state and localStorage', () => {
      store['user'] = JSON.stringify({ id: 1 })
      store['userName'] = 'T'
      store['token'] = 't'
      store['role'] = 'admin'
      store['isNameCustomized'] = 'true'
      store['redirect_schedule'] = 'sched-1'

      const auth = useAuth(showNotification)
      auth.logout()

      expect(auth.user.value).toBeNull()
      expect(auth.userName.value).toBeNull()
      expect(auth.token.value).toBeNull()
      expect(auth.isAdmin.value).toBe(false)
      expect(auth.isUser.value).toBe(false)
      expect(auth.isGuest.value).toBe(false)
      expect(auth.isNameCustomized.value).toBe(false)
      expect(window.localStorage.removeItem).toHaveBeenCalledWith('token')
      expect(window.localStorage.removeItem).toHaveBeenCalledWith('redirect_schedule')
    })

    it('executes callbacks', () => {
      const cb = vi.fn()
      const auth = useAuth(showNotification)
      auth.logout([cb])
      expect(cb).toHaveBeenCalled()
    })
  })

  describe('updateName', () => {
    it('stores new token and name on success', async () => {
      const axios = {
        post: vi.fn().mockResolvedValue({
          data: { token: 'new-token' }
        })
      }

      const auth = useAuth(showNotification)
      auth.userName.value = 'Old'
      auth.token.value = 'old-token'

      const result = await auth.updateName(axios, '/api', 'New Name')

      expect(result).toBe(true)
      expect(axios.post).toHaveBeenCalledWith('/api/user/name', { name: 'New Name' }, {
        headers: { Authorization: 'Bearer old-token' }
      })
      expect(auth.token.value).toBe('new-token')
      expect(store['token']).toBe('new-token')
      expect(auth.userName.value).toBe('New Name')
      expect(store['userName']).toBe('New Name')
      expect(auth.isNameCustomized.value).toBe(true)
      expect(store['isNameCustomized']).toBe('true')
    })

    it('keeps existing token when response has no new token', async () => {
      const axios = {
        post: vi.fn().mockResolvedValue({ data: {} })
      }

      const auth = useAuth(showNotification)
      auth.token.value = 'existing-token'

      await auth.updateName(axios, '/api', 'Name')

      expect(auth.token.value).toBe('existing-token')
    })

    it('shows error and returns false on failure', async () => {
      const axios = {
        post: vi.fn().mockRejectedValue(new Error('Network error'))
      }

      const auth = useAuth(showNotification)
      const result = await auth.updateName(axios, '/api', 'Name')

      expect(result).toBe(false)
      expect(showApiError).toHaveBeenCalled()
    })
  })
})
