/** @vitest-environment jsdom */
import { beforeEach, afterEach, describe, it, expect, vi } from 'vitest'

// The composable keeps module-level singleton refs, so every test needs a
// freshly evaluated module. Dynamic import after resetModules() gives that.
const loadComposable = async () => {
  vi.resetModules()
  const mod = await import('../src/composables/useDisplayMode')
  return mod.useDisplayMode()
}

let systemPrefersDark = false
let mediaListeners = []

// This jsdom environment ships neither Storage nor matchMedia, so both are
// installed here. Bare `localStorage` inside the composable resolves to
// window.localStorage, exactly as it does in the browser.
const installLocalStorage = () => {
  const data = new Map()
  const storage = {
    getItem: (key) => (data.has(key) ? data.get(key) : null),
    setItem: (key, value) => data.set(key, String(value)),
    removeItem: (key) => data.delete(key),
    clear: () => data.clear()
  }
  Object.defineProperty(window, 'localStorage', { value: storage, configurable: true, writable: true })
}

const installMatchMedia = () => {
  mediaListeners = []
  window.matchMedia = vi.fn((query) => ({
    media: query,
    get matches() {
      return query.includes('prefers-color-scheme') ? systemPrefersDark : false
    },
    addEventListener: (_type, cb) => mediaListeners.push(cb),
    removeEventListener: (_type, cb) => {
      mediaListeners = mediaListeners.filter((l) => l !== cb)
    },
    addListener: () => {},
    removeListener: () => {}
  }))
}

const emitSystemChange = () => mediaListeners.forEach((cb) => cb({ matches: systemPrefersDark }))

beforeEach(() => {
  systemPrefersDark = false
  document.documentElement.removeAttribute('data-theme')
  document.documentElement.removeAttribute('data-present')
  installLocalStorage()
  installMatchMedia()
})

afterEach(() => {
  document.documentElement.removeAttribute('data-theme')
  document.documentElement.removeAttribute('data-present')
})

describe('useDisplayMode theme resolution', () => {
  it('defaults to system theme when nothing is stored', async () => {
    const { theme } = await loadComposable()
    expect(theme.value).toBe('system')
  })

  it('resolves system to dark when the operating system prefers dark', async () => {
    systemPrefersDark = true
    const { isDark } = await loadComposable()
    expect(isDark.value).toBe(true)
  })

  it('resolves system to light when the operating system prefers light', async () => {
    const { isDark } = await loadComposable()
    expect(isDark.value).toBe(false)
  })

  it('applies data-theme="dark" on the root element for a dark system', async () => {
    systemPrefersDark = true
    const { init } = await loadComposable()
    init()
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })

  it('applies data-theme="light" on the root element for a light system', async () => {
    const { init } = await loadComposable()
    init()
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')
  })

  it('ignores a stored theme value that is not a known theme', async () => {
    localStorage.setItem('theme', 'neon')
    const { theme } = await loadComposable()
    expect(theme.value).toBe('system')
  })

  it('re-resolves live when the system colour scheme changes while on system', async () => {
    const { init, isDark } = await loadComposable()
    init()
    expect(isDark.value).toBe(false)

    systemPrefersDark = true
    emitSystemChange()

    expect(isDark.value).toBe(true)
  })
})

describe('useDisplayMode theme selection', () => {
  it('cycles light to dark to system and back to light', async () => {
    const { theme, cycleTheme } = await loadComposable()
    cycleTheme()
    expect(theme.value).toBe('light')
    cycleTheme()
    expect(theme.value).toBe('dark')
    cycleTheme()
    expect(theme.value).toBe('system')
  })

  it('persists an explicit theme choice to localStorage', async () => {
    const { cycleTheme } = await loadComposable()
    cycleTheme()
    expect(localStorage.getItem('theme')).toBe('light')
  })

  it('keeps an explicit light choice even when the system prefers dark', async () => {
    systemPrefersDark = true
    const { setTheme, isDark } = await loadComposable()
    setTheme('light')
    expect(isDark.value).toBe(false)
  })

  it('writes the resolved theme to data-theme when explicitly set to dark', async () => {
    const { setTheme, init } = await loadComposable()
    init()
    setTheme('dark')
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })
})

describe('useDisplayMode presentation mode', () => {
  it('defaults to off when nothing is stored', async () => {
    const { presentation } = await loadComposable()
    expect(presentation.value).toBe(false)
  })

  it('toggles presentation mode and reflects it as data-present', async () => {
    const { presentation, togglePresentation, init } = await loadComposable()
    init()
    expect(document.documentElement.getAttribute('data-present')).toBe('false')

    togglePresentation()
    expect(presentation.value).toBe(true)
    expect(document.documentElement.getAttribute('data-present')).toBe('true')

    togglePresentation()
    expect(presentation.value).toBe(false)
    expect(document.documentElement.getAttribute('data-present')).toBe('false')
  })

  it('persists presentation mode to localStorage', async () => {
    const { togglePresentation } = await loadComposable()
    togglePresentation()
    expect(localStorage.getItem('presentation')).toBe('true')
  })

  it('restores a stored presentation preference on load', async () => {
    localStorage.setItem('presentation', 'true')
    const { presentation } = await loadComposable()
    expect(presentation.value).toBe(true)
  })
})

describe('useDisplayMode location overrides', () => {
  it('lets a theme in the url hash win over the stored preference', async () => {
    localStorage.setItem('theme', 'light')
    const { applyFromLocation } = await loadComposable()
    applyFromLocation('#theme=dark')
    expect(localStorage.getItem('theme')).toBe('light')
  })

  it('exposes the hash theme as the active theme without persisting it', async () => {
    const { applyFromLocation, theme } = await loadComposable()
    applyFromLocation('#theme=dark')
    expect(theme.value).toBe('dark')
    expect(localStorage.getItem('theme')).toBeNull()
  })

  it('enables presentation mode from a present=1 hash parameter', async () => {
    const { applyFromLocation, presentation } = await loadComposable()
    applyFromLocation('#present=1')
    expect(presentation.value).toBe(true)
  })

  it('keeps the stored presentation preference when the hash says nothing', async () => {
    localStorage.setItem('presentation', 'true')
    const { applyFromLocation, presentation } = await loadComposable()
    applyFromLocation('#mode=edit')
    expect(presentation.value).toBe(true)
  })

  it('reads theme and presentation from a combined hash alongside the mode param', async () => {
    const { applyFromLocation, theme, presentation } = await loadComposable()
    applyFromLocation('#mode=edit&theme=dark&present=1')
    expect(theme.value).toBe('dark')
    expect(presentation.value).toBe(true)
  })

  it('re-applies the display settings when the location hash changes', async () => {
    const { init } = await loadComposable()
    init()
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')

    window.location.hash = 'theme=dark'
    window.dispatchEvent(new HashChangeEvent('hashchange'))

    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })

  it('clears a hash theme override once the parameter is removed again', async () => {
    const { applyFromLocation, theme } = await loadComposable()
    applyFromLocation('#theme=dark')
    expect(theme.value).toBe('dark')

    applyFromLocation('#present=1')
    expect(theme.value).toBe('system')
  })

  it('clears a hash presentation override once the parameter is removed again', async () => {
    const { applyFromLocation, presentation } = await loadComposable()
    applyFromLocation('#present=1')
    expect(presentation.value).toBe(true)

    applyFromLocation('#mode=edit')
    expect(presentation.value).toBe(false)
  })

  it('falls back to stored preferences for an empty hash', async () => {
    localStorage.setItem('theme', 'dark')
    const { applyFromLocation, theme } = await loadComposable()
    applyFromLocation('')
    expect(theme.value).toBe('dark')
  })
})

describe('embed display preference resolution', () => {
  it('resolves a light attribute to the light theme', async () => {
    const { resolveEmbedTheme } = await loadComposable()
    expect(resolveEmbedTheme('light', true)).toBe('light')
  })

  it('resolves a dark attribute to the dark theme', async () => {
    const { resolveEmbedTheme } = await loadComposable()
    expect(resolveEmbedTheme('dark', false)).toBe('dark')
  })

  it('ignores a dark attribute when the fallback resolves to light', async () => {
    const { resolveEmbedTheme } = await loadComposable()
    expect(resolveEmbedTheme('system', false)).toBe('light')
  })

  it('resolves a system attribute from the fallback dark state', async () => {
    const { resolveEmbedTheme } = await loadComposable()
    expect(resolveEmbedTheme('system', true)).toBe('dark')
  })

  it('falls back to the resolved state for an empty attribute', async () => {
    const { resolveEmbedTheme } = await loadComposable()
    expect(resolveEmbedTheme('', true)).toBe('dark')
    expect(resolveEmbedTheme(null, false)).toBe('light')
  })

  it('reads a boolean true attribute as presentation on', async () => {
    const { resolveEmbedPresentation } = await loadComposable()
    expect(resolveEmbedPresentation(true, false)).toBe(true)
    expect(resolveEmbedPresentation('true', false)).toBe(true)
  })

  it('reads a boolean false attribute as presentation off', async () => {
    const { resolveEmbedPresentation } = await loadComposable()
    expect(resolveEmbedPresentation(false, true)).toBe(false)
    expect(resolveEmbedPresentation('false', true)).toBe(false)
  })

  it('keeps the fallback when the embed attribute is absent', async () => {
    const { resolveEmbedPresentation } = await loadComposable()
    expect(resolveEmbedPresentation(undefined, true)).toBe(true)
  })
})
