/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import LoginView from '../src/views/LoginView.vue'
import LoadingIcon from '../src/components/icons/LoadingIcon.vue'
import { loadingState } from '../src/utils/loadingState'

// Mock vue-i18n
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key) => key
  })
}))

describe('LoginView.vue', () => {
  beforeEach(() => {
    // Reset loading state before each test
    loadingState.isLoading = false
    loadingState.activeRequests = 0
  })

  it('renders login form properly', () => {
    const wrapper = mount(LoginView, {
      props: {
        email: '',
        alertMessage: ''
      }
    })

    expect(wrapper.find('input[type="email"]').exists()).toBe(true)
    expect(wrapper.find('button[type="submit"]').exists()).toBe(true)
    expect(wrapper.findComponent(LoadingIcon).exists()).toBe(false)
  })

  it('displays alert message when provided', () => {
    const wrapper = mount(LoginView, {
      props: {
        email: '',
        alertMessage: 'Test alert message'
      }
    })

    expect(wrapper.text()).toContain('Test alert message')
  })

  it('disables submit button with an invalid email', async () => {
    const wrapper = mount(LoginView, {
      props: {
        email: 'invalid-email',
        alertMessage: ''
      }
    })

    const button = wrapper.find('button[type="submit"]')
    expect(button.element.disabled).toBe(true)
  })

  it('enables submit button with a valid email', async () => {
    const wrapper = mount(LoginView, {
      props: {
        email: 'test@example.com',
        alertMessage: ''
      }
    })

    const button = wrapper.find('button[type="submit"]')
    expect(button.element.disabled).toBe(false)
  })

  it('freezes the form and shows spinner when loading', async () => {
    const wrapper = mount(LoginView, {
      props: {
        email: 'test@example.com',
        alertMessage: ''
      }
    })

    // Simulate loading state
    loadingState.isLoading = true
    await wrapper.vm.$nextTick()

    // Email input should be disabled
    const input = wrapper.find('input[type="email"]')
    expect(input.element.disabled).toBe(true)

    // Submit button should be disabled and show loading spinner
    const button = wrapper.find('button[type="submit"]')
    expect(button.element.disabled).toBe(true)
    expect(wrapper.findComponent(LoadingIcon).exists()).toBe(true)
  })

  it('emits login event when submit button is clicked', async () => {
    const wrapper = mount(LoginView, {
      props: {
        email: 'test@example.com',
        alertMessage: ''
      }
    })

    const form = wrapper.find('form')
    await form.trigger('submit.prevent')

    expect(wrapper.emitted('login')).toBeTruthy()
  })

  it('renders honeypot field', () => {
    const wrapper = mount(LoginView, {
      props: {
        email: '',
        alertMessage: ''
      }
    })

    const honeypot = wrapper.find('input[name="website"]')
    expect(honeypot.exists()).toBe(true)
    expect(honeypot.element.style.position).toBe('absolute')
  })

  it('triggers cooldown and clears input field on submit', async () => {
    vi.useFakeTimers()
    const wrapper = mount(LoginView, {
      props: {
        email: 'test@example.com',
        alertMessage: ''
      }
    })

    const form = wrapper.find('form')
    await form.trigger('submit.prevent')

    // Expect login event emitted
    expect(wrapper.emitted('login')).toBeTruthy()

    // Expect email input field cleared (emitted update:email with empty string)
    expect(wrapper.emitted('update:email')).toBeTruthy()
    expect(wrapper.emitted('update:email')[0]).toEqual([''])

    // On submit, isCooldownActive becomes true. Let's make sure the button is disabled even with a valid email.
    // We can set props to check button state with valid email during cooldown
    await wrapper.setProps({ email: 'another@example.com' })
    const button = wrapper.find('button[type="submit"]')
    expect(button.element.disabled).toBe(true)

    // Fast-forward 5 seconds
    vi.advanceTimersByTime(5000)
    await wrapper.vm.$nextTick()

    // Button should be enabled now that cooldown has ended
    expect(button.element.disabled).toBe(false)
    vi.useRealTimers()
  })
})
