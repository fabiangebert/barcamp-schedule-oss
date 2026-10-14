import { ref, computed } from 'vue'

const THEMES = ['system', 'light', 'dark']
const STORAGE_THEME = 'theme'
const STORAGE_PRESENTATION = 'presentation'
const DARK_SCHEME_QUERY = '(prefers-color-scheme: dark)'

const readStorage = (key) => {
  try {
    return localStorage.getItem(key)
  } catch {
    return null
  }
}

const writeStorage = (key, value) => {
  try {
    localStorage.setItem(key, value)
  } catch {
    // Storage may be unavailable (private mode, disabled cookies)
  }
}

const prefersDark = () =>
  typeof window !== 'undefined' && typeof window.matchMedia === 'function'
    ? window.matchMedia(DARK_SCHEME_QUERY).matches
    : false

const storedTheme = ref(
  THEMES.includes(readStorage(STORAGE_THEME)) ? readStorage(STORAGE_THEME) : 'system'
)
const storedPresentation = ref(readStorage(STORAGE_PRESENTATION) === 'true')
const systemPrefersDark = ref(prefersDark())

// URL hash values win for the current page load without being persisted,
// so a projector link can carry its own display settings.
const themeOverride = ref(null)
const presentationOverride = ref(null)

const theme = computed(() => themeOverride.value ?? storedTheme.value)
const presentation = computed(() => presentationOverride.value ?? storedPresentation.value)
const isDark = computed(() => (theme.value === 'system' ? systemPrefersDark.value : theme.value === 'dark'))

let mediaQuery = null
let listening = false

const applyToDocument = () => {
  if (typeof document === 'undefined') return
  const { documentElement } = document
  documentElement.setAttribute('data-theme', isDark.value ? 'dark' : 'light')
  documentElement.setAttribute('data-present', String(presentation.value))
}

const parseHash = (hash) => {
  const params = new Map()
  const query = String(hash || '').replace(/^#/, '')
  if (!query) return params
  query.split('&').forEach((pair) => {
    const [key, value] = pair.split('=')
    if (key) params.set(decodeURIComponent(key), decodeURIComponent(value ?? ''))
  })
  return params
}

const readFlag = (value) => {
  if (value === '1' || value === 'true') return true
  if (value === '0' || value === 'false') return false
  return null
}

/**
 * The web component lives in a shadow root that does not inherit data-theme
 * from the host page, so it re-applies the resolved state to its own
 * container. An explicit attribute wins; otherwise the caller's fallback
 * (the shared composable state) decides.
 */
export const resolveEmbedTheme = (attribute, fallbackIsDark) => {
  const value = String(attribute ?? '').toLowerCase()
  if (value === 'light' || value === 'dark') return value
  return fallbackIsDark ? 'dark' : 'light'
}

export const resolveEmbedPresentation = (attribute, fallback) => {
  if (attribute === true || attribute === 'true') return true
  if (attribute === false || attribute === 'false') return false
  return fallback
}

export function useDisplayMode() {
  systemPrefersDark.value = prefersDark()

  const setTheme = (value) => {
    if (!THEMES.includes(value)) return
    storedTheme.value = value
    writeStorage(STORAGE_THEME, value)
    themeOverride.value = null
    applyToDocument()
  }

  const cycleTheme = () => {
    const next = THEMES[(THEMES.indexOf(theme.value) + 1) % THEMES.length]
    setTheme(next)
  }

  const togglePresentation = () => {
    presentationOverride.value = !presentation.value
    storedPresentation.value = presentationOverride.value
    writeStorage(STORAGE_PRESENTATION, String(presentationOverride.value))
    applyToDocument()
  }

  const applyFromLocation = (hash) => {
    const params = parseHash(hash)
    // The current hash is authoritative: a parameter that is gone clears any
    // override it set earlier, so editing the hash back to a plain link
    // returns to the stored preference.
    const hashTheme = params.get('theme')
    themeOverride.value = THEMES.includes(hashTheme) ? hashTheme : null
    const hashPresent = readFlag(params.get('present'))
    presentationOverride.value = hashPresent
    applyToDocument()
  }

  const init = () => {
    if (typeof window !== 'undefined' && !listening) {
      if (typeof window.matchMedia === 'function') {
        mediaQuery = window.matchMedia(DARK_SCHEME_QUERY)
        const onChange = (event) => {
          systemPrefersDark.value = event.matches
          applyToDocument()
        }
        if (mediaQuery.addEventListener) {
          mediaQuery.addEventListener('change', onChange)
        } else if (mediaQuery.addListener) {
          mediaQuery.addListener(onChange)
        }
      }
      // A hash-only navigation does not reload the page, so pick up display
      // params that were added or edited after the initial load.
      window.addEventListener('hashchange', () => applyFromLocation(window.location.hash))
      listening = true
    }
    applyToDocument()
  }

  return {
    themes: THEMES,
    theme,
    presentation,
    isDark,
    setTheme,
    cycleTheme,
    togglePresentation,
    applyFromLocation,
    init,
    resolveEmbedTheme,
    resolveEmbedPresentation
  }
}
