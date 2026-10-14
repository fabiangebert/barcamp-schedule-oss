import { createI18n } from 'vue-i18n'
import en from './locales/en.json'
import de from './locales/de.json'

const messages = {
  en,
  de
}

const getBrowserLocale = () => {
  let savedLocale = null
  try {
    if (typeof window !== 'undefined' && window.localStorage) {
      savedLocale = localStorage.getItem('locale')
    }
  } catch {
    // ignore
  }
  if (savedLocale && messages[savedLocale]) {
    return savedLocale
  }
  const navigatorLang = navigator.languages ? navigator.languages[0] : navigator.language
  if (!navigatorLang) return 'en'
  const lang = navigatorLang.split('-')[0]
  return messages[lang] ? lang : 'en'
}

const i18n = createI18n({
  legacy: false,
  globalInjection: true,
  locale: getBrowserLocale(),
  fallbackLocale: 'en',
  messages,
})

export default i18n
