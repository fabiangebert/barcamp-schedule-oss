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

// Notification stub that only renders when visible so tests can trigger
// the confirm action of the currently shown notification
vi.mock('../src/components/modals/NotificationModal.vue', () => ({
  default: {
    props: { visible: Boolean },
    template:
      '<div v-if="visible" data-testid="notification-modal" @click="$emit(\'confirm\')"></div>'
  }
}))

vi.mock('../src/views/LoginView.vue', () => ({
  default: { template: '<div data-testid="login-view">LoginView</div>' }
}))
vi.mock('../src/components/layout/Navbar.vue', () => ({
  default: { template: '<div>Navbar</div>' }
}))
vi.mock('../src/components/schedule/ScheduleHeader.vue', () => ({
  default: { template: '<div>ScheduleHeader</div>' }
}))
vi.mock('../src/components/schedule/ScheduleGrid.vue', () => ({
  default: { template: '<div>ScheduleGrid</div>' }
}))
vi.mock('../src/components/documentation/DocumentationModal.vue', () => ({
  default: { template: '<div>DocumentationModal</div>' }
}))
vi.mock('../src/components/documentation/DocumentationOverview.vue', () => ({
  default: { template: '<div>DocumentationOverview</div>' }
}))
vi.mock('../src/views/AdminView.vue', () => ({
  default: { template: '<div>AdminView</div>' }
}))
vi.mock('../src/components/modals/ConfirmationModal.vue', () => ({
  default: { template: '<div>ConfirmationModal</div>' }
}))
vi.mock('../src/components/schedule/ScheduleList.vue', () => ({
  default: { template: '<div>ScheduleList</div>' }
}))
vi.mock('../src/components/schedule/NewScheduleModal.vue', () => ({
  default: { template: '<div>NewScheduleModal</div>' }
}))
vi.mock('../src/components/share/ShareModal.vue', () => ({
  default: { template: '<div>ShareModal</div>' }
}))
vi.mock('../src/components/modals/NameModal.vue', () => ({
  default: { template: '<div>NameModal</div>' }
}))
vi.mock('../src/components/modals/EventFeedbackModal.vue', () => ({
  default: { template: '<div>EventFeedbackModal</div>' }
}))

const DELETED_SCHEDULE_ID = 'deleted-schedule-id'
const DELETED_SCHEDULE_URL = `/api/schedules/${DELETED_SCHEDULE_ID}`

const seedGuestStorage = () => {
  localStorage.setItem('user', JSON.stringify({ sub: 'guest@example.com', role: 'guest' }))
  localStorage.setItem('userName', 'Guest User')
  localStorage.setItem('token', 'guest-jwt-token')
  localStorage.setItem('role', 'guest')
  localStorage.setItem('isNameCustomized', 'true')
  localStorage.setItem('redirect_schedule', DELETED_SCHEDULE_ID)
}

// The mock rejects with 404 for the first `notFoundCalls` schedule requests and
// with 500 afterwards. This bounds the pre-fix refetch loop so tests stay fast:
// pre-fix the loop performs 1 initial + `notFoundCalls` refetches, post-fix only
// the initial request happens because redirect_schedule is cleared on 404.
const mockDeletedScheduleResponses = (notFoundCalls) => {
  let scheduleCalls = 0
  axios.get.mockImplementation((url) => {
    if (url === DELETED_SCHEDULE_URL) {
      scheduleCalls++
      if (scheduleCalls <= notFoundCalls) {
        return Promise.reject({ response: { status: 404 } })
      }
      return Promise.reject({ response: { status: 500 } })
    }
    return Promise.resolve({ data: { data: [] } })
  })
  return () => scheduleCalls
}

describe('App.vue guest schedule-not-found handling', () => {
  let originalLocation
  let store

  beforeEach(() => {
    originalLocation = window.location
    delete window.location
    window.location = new URL('http://localhost/')

    store = {}
    vi.stubGlobal('localStorage', {
      getItem: vi.fn((key) => store[key] || null),
      setItem: vi.fn((key, value) => {
        store[key] = value !== null && value !== undefined ? value.toString() : null
      }),
      removeItem: vi.fn((key) => {
        delete store[key]
      }),
      clear: vi.fn(() => {
        for (const key in store) delete store[key]
      })
    })

    vi.spyOn(window.history, 'replaceState').mockImplementation(() => {})
    vi.spyOn(window.history, 'pushState').mockImplementation(() => {})
  })

  afterEach(() => {
    window.location = originalLocation
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('clears redirect_schedule on 404 so the guest schedule is not re-fetched in a loop', async () => {
    seedGuestStorage()
    const getScheduleCalls = mockDeletedScheduleResponses(3)

    const wrapper = mount(App, {
      global: {
        plugins: [i18n],
        mocks: { $t: (key) => key }
      }
    })

    await vi.waitFor(() => {
      expect(
        wrapper.find('[data-testid="notification-modal"]').exists()
      ).toBe(true)
    })

    expect(getScheduleCalls()).toBe(1)
    expect(store['redirect_schedule']).toBeUndefined()
  })

  it('logs the guest out immediately when clicking Log out on the not-found dialog', async () => {
    seedGuestStorage()
    mockDeletedScheduleResponses(3)

    const wrapper = mount(App, {
      global: {
        plugins: [i18n],
        mocks: { $t: (key) => key }
      }
    })

    await vi.waitFor(() => {
      expect(
        wrapper.find('[data-testid="notification-modal"]').exists()
      ).toBe(true)
    })

    await wrapper.find('[data-testid="notification-modal"]').trigger('click')

    expect(store['token']).toBeUndefined()
    expect(store['role']).toBeUndefined()
    expect(store['redirect_schedule']).toBeUndefined()
    expect(
      wrapper.find('[data-testid="login-view"]').exists()
    ).toBe(true)
  })
})
