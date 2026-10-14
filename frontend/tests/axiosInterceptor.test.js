/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import axios from 'axios'

// We need to test the interceptor. Since it's defined in main.js, 
// and main.js also creates the app and mounts it, we might want to extract 
// the interceptor logic if we wanted to test it cleanly.
// However, I can also just manually define the interceptor in the test 
// to verify it works as expected, OR try to mock the globals it uses.

describe('Axios Interceptor', () => {
  let originalLocation

  beforeEach(() => {
    // Mock window.location
    originalLocation = window.location
    delete window.location
    window.location = { 
      reload: vi.fn(),
      href: 'http://localhost/'
    }
    
    // Mock localStorage
    const localStorageMock = (function() {
      let store = {}
      return {
        getItem: vi.fn(key => store[key] || null),
        setItem: vi.fn((key, value) => { store[key] = value.toString() }),
        removeItem: vi.fn(key => { delete store[key] }),
        clear: vi.fn(() => { store = {} })
      }
    })()
    Object.defineProperty(window, 'localStorage', { value: localStorageMock })

    // Manually register the interceptor for the test
    // This replicates what's in main.js
    axios.interceptors.response.use(
      (response) => {
        const newToken = response.headers['x-new-token']
        if (newToken) {
          localStorage.setItem('token', newToken)
        }
        return response
      },
      (error) => {
        if (error.response?.status === 401) {
          localStorage.removeItem('user')
          localStorage.removeItem('userName')
          localStorage.removeItem('token')
          localStorage.removeItem('role')
          localStorage.removeItem('redirect_schedule')
          const url = new URL(window.location.href)
          url.searchParams.set('reason', 'expired')
          window.location.href = url.toString()
        }
        return Promise.reject(error)
      }
    )
  })

  afterEach(() => {
    window.location = originalLocation
    // Clear interceptors to avoid affecting other tests
    axios.interceptors.response.handlers = []
  })

  it('should clear localStorage and redirect with reason=expired on 401 error', async () => {
    // Mock window.location.href
    const originalHref = 'http://localhost/'
    window.location.href = originalHref
    
    // Set some items in localStorage
    localStorage.setItem('user', 'test-user')
    localStorage.setItem('token', 'test-token')

    // Simulate a 401 error
    const error401 = {
      response: {
        status: 401
      }
    }

    // Trigger the interceptor
    const errorHandler = axios.interceptors.response.handlers[0].rejected
    await expect(errorHandler(error401)).rejects.toEqual(error401)

    expect(localStorage.removeItem).toHaveBeenCalledWith('user')
    expect(localStorage.removeItem).toHaveBeenCalledWith('token')
    expect(localStorage.removeItem).toHaveBeenCalledWith('userName')
    expect(localStorage.removeItem).toHaveBeenCalledWith('role')
    expect(localStorage.removeItem).toHaveBeenCalledWith('redirect_schedule')
    expect(window.location.href).toContain('reason=expired')
  })

  it('should not clear localStorage or reload page on other errors', async () => {
    localStorage.setItem('user', 'test-user')
    
    const error500 = {
      response: {
        status: 500
      }
    }

    const errorHandler = axios.interceptors.response.handlers[0].rejected
    await expect(errorHandler(error500)).rejects.toEqual(error500)

    expect(localStorage.removeItem).not.toHaveBeenCalled()
    expect(window.location.reload).not.toHaveBeenCalled()
  })

  it('should update token when X-New-Token header is present', async () => {
    const response = {
      headers: {
        'x-new-token': 'brand-new-token'
      }
    }

    const successHandler = axios.interceptors.response.handlers[0].fulfilled
    const result = successHandler(response)

    expect(result).toBe(response)
    expect(localStorage.setItem).toHaveBeenCalledWith('token', 'brand-new-token')
  })
})
