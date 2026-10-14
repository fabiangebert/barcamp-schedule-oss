/**
 * @vitest-environment jsdom
 */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import EmbedPanel from '../src/components/share/EmbedPanel.vue'
import { API_BASE } from '../src/constants'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key) => key }),
  createI18n: () => ({ global: { t: (key) => key } })
}))

// The web component bundle pulls in the whole app; stub it out for the preview.
vi.mock('../src/web-component-entry.js', () => ({}))

/**
 * jsdom's location cannot be set directly; replaceState rewrites the URL.
 */
const setLocation = (path) => {
  window.history.replaceState(null, '', path)
}

describe('EmbedPanel embed snippet', () => {
  beforeEach(() => {
    setLocation('/')
  })

  const codeContent = (wrapper) => wrapper.find('code').text()

  it('points the script tag at the app host folder, not the API', () => {
    setLocation('/index.html')
    const wrapper = mount(EmbedPanel, {
      props: { scheduleId: 'abc-123' },
      global: { stubs: { BaseButton: { template: '<button><slot /></button>' } } }
    })

    const code = codeContent(wrapper)
    expect(code).toContain('src="http://localhost:3000/embed.js"')
    expect(code).toContain(`api-base="${API_BASE}"`)
    expect(code.includes(`src="${API_BASE}/embed.js"`)).toBe(false)
  })

  it('strips index.html from a subdirectory path', () => {
    setLocation('/schedules/index.html')
    const wrapper = mount(EmbedPanel, {
      props: { scheduleId: 'abc-123' },
      global: { stubs: { BaseButton: { template: '<button><slot /></button>' } } }
    })

    expect(codeContent(wrapper)).toContain(
      'src="http://localhost:3000/schedules/embed.js"'
    )
  })

  it('keeps a trailing directory slash from mangling the path', () => {
    setLocation('/schedules/')
    const wrapper = mount(EmbedPanel, {
      props: { scheduleId: 'abc-123' },
      global: { stubs: { BaseButton: { template: '<button><slot /></button>' } } }
    })

    expect(codeContent(wrapper)).toContain(
      'src="http://localhost:3000/schedules/embed.js"'
    )
    expect(codeContent(wrapper).includes('/schedules//embed.js')).toBe(false)
  })

  it('includes the schedule id in the custom element', () => {
    const wrapper = mount(EmbedPanel, {
      props: { scheduleId: 'sched-42' },
      global: { stubs: { BaseButton: { template: '<button><slot /></button>' } } }
    })

    expect(codeContent(wrapper)).toContain('schedule-id="sched-42"')
  })
})
