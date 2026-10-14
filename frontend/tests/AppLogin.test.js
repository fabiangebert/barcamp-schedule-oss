/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import App from '../src/App.vue'
import axios from 'axios'
import i18n from '../src/i18n'

// Mocking axios
vi.mock('axios')

// Mocking constants
vi.mock('../src/constants', () => ({
  API_BASE: '/api',
  MAX_UPLOAD_SIZE: 10485760,
  TAB_STALENESS_THRESHOLD: 60000
}))

// Mocking i18n
// Removed top-level mock to use the real i18n instance from src/i18n.js

// Mocking components to avoid heavy rendering
vi.mock('../src/components/LoginView.vue', () => ({
  default: { template: '<div>LoginView</div>' }
}))
vi.mock('../src/components/layout/Navbar.vue', () => ({
  default: { template: '<div>Navbar</div>' }
}))
vi.mock('../src/components/ScheduleHeader.vue', () => ({
  default: { template: '<div>ScheduleHeader</div>' }
}))
vi.mock('../src/components/ScheduleGrid.vue', () => ({
  default: { template: '<div>ScheduleGrid</div>' }
}))
vi.mock('../src/components/DocumentationModal.vue', () => ({
  default: { template: '<div>DocumentationModal</div>' }
}))
vi.mock('../src/components/DocumentationOverview.vue', () => ({
  default: { template: '<div>DocumentationOverview</div>' }
}))
vi.mock('../src/views/AdminView.vue', () => ({
  default: { template: '<div>AdminView</div>' }
}))
vi.mock('../src/components/ConfirmationModal.vue', () => ({
  default: { template: '<div>ConfirmationModal</div>' }
}))
vi.mock('../src/components/NotificationModal.vue', () => ({
  default: { template: '<div>NotificationModal</div>' }
}))
vi.mock('../src/components/ScheduleList.vue', () => ({
  default: { template: '<div>ScheduleList</div>' }
}))
vi.mock('../src/components/NewScheduleModal.vue', () => ({
  default: { template: '<div>NewScheduleModal</div>' }
}))
vi.mock('../src/components/ShareModal.vue', () => ({
  default: { template: '<div>ShareModal</div>' }
}))
vi.mock('../src/components/NameModal.vue', () => ({
  default: { template: '<div>NameModal</div>' }
}))

describe('App.vue Login logic', () => {
  let originalLocation
  let replaceStateSpy

  beforeEach(() => {
    // Mock window.location
    originalLocation = window.location
    delete window.location
    window.location = new URL('http://localhost/')

    // Mock history.replaceState
    replaceStateSpy = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {})

    // Mock localStorage
    const store = {}
    vi.stubGlobal('localStorage', {
      getItem: vi.fn(key => store[key] || null),
      setItem: vi.fn((key, value) => { store[key] = value !== null && value !== undefined ? value.toString() : null }),
      removeItem: vi.fn(key => { delete store[key] }),
      clear: vi.fn(() => { for (let key in store) delete store[key] })
    })

    // Mock i18n
    // Already mocked at top level
  })

  afterEach(() => {
    window.location = originalLocation
    vi.restoreAllMocks()
  })

  it('should clear reason=expired from URL on mount', async () => {
    window.location = new URL('http://localhost/#reason=expired')
    
    mount(App, {
      global: {
        plugins: [i18n],
        mocks: {
          $t: (key) => key
        },
        stubs: {
          Navbar: true,
          LoginView: true
        }
      }
    })

    expect(replaceStateSpy).toHaveBeenCalledWith({}, expect.any(String), '/')
  })

  it('should clear token from URL after successful login', async () => {
    window.location = new URL('http://localhost/#token=valid-token')
    
    // Mock fetchSchedules which is called after login
    axios.get.mockImplementation((url) => {
      if (url.includes('/auth/login')) {
        return Promise.resolve({
          data: {
            token: 'new-jwt-token',
            user: { sub: 'user@example.com', role: 'user', name: 'Test User' }
          }
        })
      }
      if (url.includes('/schedules')) {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve({ data: {} })
    })

    mount(App, {
      global: {
        plugins: [i18n],
        mocks: {
          $t: (key) => key
        }
      }
    })

    // Wait for login promise to resolve
    await vi.waitFor(() => {
        expect(replaceStateSpy).toHaveBeenCalledWith({}, expect.any(String), '/')
    })
  })

  it('should clear token from URL after failed login (BREAKS INFINITE LOOP)', async () => {
    window.location = new URL('http://localhost/#token=invalid-token')
    
    axios.get.mockRejectedValue({
      response: { status: 401, data: { error: 'Invalid token' } }
    })

    mount(App, {
      global: {
        plugins: [i18n],
        mocks: {
          $t: (key) => key
        }
      }
    })

    // Wait for login promise to catch
    await vi.waitFor(() => {
        expect(replaceStateSpy).toHaveBeenCalledWith({}, expect.any(String), '/')
    })
    
    // Check if replaceState was called even on error
    expect(replaceStateSpy).toHaveBeenCalled()
  })
})
