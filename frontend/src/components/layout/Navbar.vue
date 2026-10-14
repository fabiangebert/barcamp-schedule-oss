<template>
  <header class="bg-surface-inverse text-white py-2 px-4 md:px-8 mb-4 shadow-md z-50">
    <div class="w-full flex items-center justify-between flex-wrap">
      <h1
          class="cursor-pointer max-md:text-sm md:text-lg font-extrabold tracking-tight transition-opacity hover:opacity-80 text-nowrap"
          @click="$emit('go-home')"
      >
        {{ appTitle }}
      </h1>
      <div class="flex items-center gap-4">
        <!-- Loading indicator -->
        <div v-if="displayLoading" class="flex items-center">
          <span class="relative flex h-3 w-3">
            <span class="animate-spin text-white h-4 w-4">
              <LoadingIcon
                viewBox="0 0 24 24"
                width="16px"
                height="16px"
                fill="none"
              />
            </span>
          </span>
        </div>

        <!-- Connection Status -->
        <div
            v-if="realtime && !isConnected"
            class="flex items-center gap-1.5 rounded-md bg-white/10 px-2 py-1 text-3xs font-black uppercase tracking-widest text-secondary border border-secondary/20 animate-pulse"
        >
            <span class="relative flex h-2 w-2">
              <span
                  class="animate-ping absolute inline-flex h-full w-full rounded-full bg-secondary opacity-75"
              ></span>
              <span
                  class="relative inline-flex rounded-full h-2 w-2 bg-secondary"
              ></span>
            </span>
          {{ t('common.offline') }}
        </div>

        <!-- Auth Section -->
        <div class="flex items-center gap-2">
          <button
              class="flex items-center justify-center rounded-md border border-white/30 w-7 h-7 text-3xs font-bold text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/20 transition-colors uppercase"
              @click="toggleLanguage"
          >
            {{ currentLocale }}
          </button>
          <button
              data-testid="theme-toggle"
              class="flex items-center justify-center rounded-md border border-white/30 w-7 h-7 text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/20 transition-colors"
              :title="themeLabel"
              :aria-label="themeLabel"
              @click="cycleTheme"
          >
            <SunIcon v-if="theme === 'light'" data-testid="theme-icon-light" class="w-3.5 h-3.5" />
            <MoonIcon v-else-if="theme === 'dark'" data-testid="theme-icon-dark" class="w-3.5 h-3.5" />
            <MonitorIcon v-else data-testid="theme-icon-system" class="w-3.5 h-3.5" />
          </button>
          <button
              data-testid="presentation-toggle"
              class="flex items-center justify-center rounded-md border w-7 h-7 text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/20 transition-colors"
              :class="presentation ? 'border-tertiary bg-tertiary/20 text-tertiary' : 'border-white/30'"
              :title="t('navbar.presentationMode')"
              :aria-label="t('navbar.presentationMode')"
              :aria-pressed="String(presentation)"
              @click="togglePresentation"
          >
            <PresentationIcon class="w-3.5 h-3.5" />
          </button>
          <div v-if="user" class="flex items-center gap-3">
            <div
                class="flex flex-col items-end cursor-pointer hover:opacity-80 transition-opacity"
                :title="t('auth.changeName')"
                @click="$emit('edit-name')"
            >
              <span class="text-xs font-black text-nowrap">{{ userName || user }}</span>
            </div>
            <div class="h-6 w-px bg-white/20 mx-1"></div>
            <button
                v-if="isAdmin"
                class="rounded-md border border-white/30 px-3 py-1 text-xs font-bold text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/20 transition-colors"
                :class="{ 'bg-white/20': showUserManagement }"
                @click="$emit('toggle-admin')"
            >
              {{ t('navbar.admin') }}
            </button>
            <button
                :title="t('navbar.logout')"
                class="rounded-md border border-white/30 px-3 py-1 text-xs font-bold text-white hover:bg-white/10 focus:outline-none focus:ring-2 focus:ring-white/20"
                @click="$emit('logout')"
            >
              <LogoutIcon class="w-3 h-4" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </header>
</template>

<script setup>
import {ref, watch, computed} from 'vue'
import { useI18n } from 'vue-i18n'
import i18n from '../../i18n'
import { useDisplayMode } from '../../composables/useDisplayMode'
import LoadingIcon from '../icons/LoadingIcon.vue'
import LogoutIcon from '../icons/LogoutIcon.vue'
import SunIcon from '../icons/SunIcon.vue'
import MoonIcon from '../icons/MoonIcon.vue'
import MonitorIcon from '../icons/MonitorIcon.vue'
import PresentationIcon from '../icons/PresentationIcon.vue'
const { t } = useI18n()

const currentLocale = ref(i18n.global.locale.value)
const { theme, presentation, cycleTheme, togglePresentation } = useDisplayMode()

const themeLabel = computed(() => {
  const labels = {
    light: t('navbar.themeLight'),
    dark: t('navbar.themeDark'),
    system: t('navbar.themeSystem')
  }
  return labels[theme.value] ?? t('navbar.themeSystem')
})

const toggleLanguage = () => {
  const newLocale = currentLocale.value === 'en' ? 'de' : 'en'
  i18n.global.locale.value = newLocale
  currentLocale.value = newLocale
  localStorage.setItem('locale', newLocale)
}

const appTitle = import.meta.env.VITE_APP_TITLE || t('navbar.title')

const props = defineProps({
  realtime: {type: Boolean, default: false},
  isConnected: {type: Boolean, default: false},
  loading: {type: Boolean, default: false},
  user: {type: Object, default: null},
  userName: {type: String, default: null},
  email: {type: String, default: null},
  isAdmin: {type: Boolean, default: false},
  showUserManagement: {type: Boolean, default: false}
})

const displayLoading = ref(false)
let loadingTimeout = null

watch(() => props.loading, (newVal) => {
  if (newVal) {
    if (loadingTimeout) clearTimeout(loadingTimeout)
    displayLoading.value = true
  } else {
    if (loadingTimeout) clearTimeout(loadingTimeout)
    loadingTimeout = setTimeout(() => {
      displayLoading.value = false
      loadingTimeout = null
    }, 1000)
  }
}, {immediate: true})

defineEmits(['go-home', 'update:email', 'login', 'logout', 'toggle-admin', 'edit-name'])
</script>
