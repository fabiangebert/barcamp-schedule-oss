/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { mount } from '@vue/test-utils'
import Navbar from '../src/components/layout/Navbar.vue'

vi.mock('vue-i18n', async (importOriginal) => {
  const actual = await importOriginal()
  return {
    ...actual,
    useI18n: () => ({ t: (key) => key })
  }
})

vi.mock('../src/composables/useDisplayMode', () => ({
  useDisplayMode: () => mockDisplayMode
}))

let mockDisplayMode

beforeEach(() => {
  mockDisplayMode = {
    theme: ref('system'),
    presentation: ref(false),
    isDark: ref(false),
    cycleTheme: vi.fn(),
    togglePresentation: vi.fn()
  }
})

const mountNavbar = () => mount(Navbar)

describe('Navbar display mode controls', () => {
  it('renders a control that cycles the colour theme', () => {
    const wrapper = mountNavbar()
    const button = wrapper.get('[data-testid="theme-toggle"]')
    button.trigger('click')
    expect(mockDisplayMode.cycleTheme).toHaveBeenCalled()
  })

  it('renders a control that toggles presentation mode', () => {
    const wrapper = mountNavbar()
    const button = wrapper.get('[data-testid="presentation-toggle"]')
    button.trigger('click')
    expect(mockDisplayMode.togglePresentation).toHaveBeenCalled()
  })

  it('marks the presentation control as active while presentation mode is on', async () => {
    mockDisplayMode.presentation.value = true
    const wrapper = mountNavbar()
    await wrapper.vm.$nextTick()
    expect(wrapper.get('[data-testid="presentation-toggle"]').attributes('aria-pressed')).toBe('true')
  })

  it('marks the presentation control as inactive while presentation mode is off', () => {
    const wrapper = mountNavbar()
    expect(wrapper.get('[data-testid="presentation-toggle"]').attributes('aria-pressed')).toBe('false')
  })

  it('shows the sun icon while the light theme is active', () => {
    mockDisplayMode.theme.value = 'light'
    const wrapper = mountNavbar()
    expect(wrapper.find('[data-testid="theme-icon-light"]').exists()).toBe(true)
  })

  it('shows the moon icon while the dark theme is active', () => {
    mockDisplayMode.theme.value = 'dark'
    const wrapper = mountNavbar()
    expect(wrapper.find('[data-testid="theme-icon-dark"]').exists()).toBe(true)
  })

  it('shows the monitor icon while the theme follows the system', () => {
    mockDisplayMode.theme.value = 'system'
    const wrapper = mountNavbar()
    expect(wrapper.find('[data-testid="theme-icon-system"]').exists()).toBe(true)
  })
})
