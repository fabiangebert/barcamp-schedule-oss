<!--suppress CssUnusedSymbol -->
<template>
  <div
    ref="container"
    class="barcamp-schedule-container flex flex-col"
    :class="{
      'max-md:hidden flex': isHideMobile,
      'md:hidden flex': isHideDesktop
    }"
    :data-theme="resolvedTheme"
    :data-present="String(resolvedPresentation)"
  >
    <!-- Manual injection ensures Preflight is active inside the Shadow Root -->
    <component :is="'style'">{{ tailwindStyles }}</component>
    <div v-if="loading" class="p-8 text-center text-gray-mid">Loading...</div>
    <div v-else-if="error" class="p-8 text-center text-red-500">
      {{ error }}
    </div>
    <ScheduleGrid v-else-if="schedule" :schedule="schedule" :read-only="true">
    </ScheduleGrid>
  </div>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import axios from 'axios'

import { API_BASE } from './constants'
import ScheduleGrid from './components/schedule/ScheduleGrid.vue'
import { useDisplayMode } from './composables/useDisplayMode'

import tailwindStyles from './assets/main.css?inline'

const { t, locale: i18nLocale } = useI18n()
const {
  isDark,
  presentation: sharedPresentation,
  resolveEmbedTheme,
  resolveEmbedPresentation
} = useDisplayMode()

const props = defineProps({
  scheduleId: {
    type: String,
    required: true
  },
  apiBase: {
    type: String,
    default: ''
  },
  locale: {
    type: String,
    default: 'auto'
  },
  hideMobile: {
    type: [Boolean, String],
    default: false
  },
  hideDesktop: {
    type: [Boolean, String],
    default: false
  },
  theme: {
    type: String,
    default: undefined
  },
  presentation: {
    type: [Boolean, String],
    default: undefined
  }
})

// The shadow root does not inherit data-theme from the host page, so the
// resolved state is mirrored onto the container. An explicit theme or
// presentation attribute on the custom element wins over the shared state.
const resolvedTheme = computed(() => resolveEmbedTheme(props.theme, isDark.value))
const resolvedPresentation = computed(() => resolveEmbedPresentation(props.presentation, sharedPresentation.value))

const isHideMobile = computed(() => props.hideMobile === true || props.hideMobile === 'true')
const isHideDesktop = computed(() => props.hideDesktop === true || props.hideDesktop === 'true')

const loading = ref(true)
const error = ref(null)
const schedule = ref(null)
const container = ref(null)

const updateLocale = (newLocale) => {
  if (newLocale && ['en', 'de'].includes(newLocale)) {
    i18nLocale.value = newLocale
  }
}

if (props.locale) {
  updateLocale(props.locale)
}

const fetchData = async () => {
  if (!props.scheduleId) return

  loading.value = true
  error.value = null

  try {
    const baseUrl = (props.apiBase || API_BASE).replace(/\/$/, '')
    const url = `${baseUrl}/schedules/${props.scheduleId}`
    const response = await axios.get(url)
    if (response.status < 200 || response.status >= 300) {
      error.value = t('auth.failedToLoadSchedule')
      return
    }
    const resData = response.data
    schedule.value = resData.data || resData
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

fetchData()
watch(() => props.scheduleId, fetchData)
watch(() => props.locale, (newLocale) => {
  if (newLocale && newLocale !== 'auto') {
    updateLocale(newLocale)
  }
})
</script>

<style>
@import 'tailwindcss';

:host {
  display: block;
  width: 100%;
}

.barcamp-schedule-container {
  width: 100%;
  font-family: sans-serif;
  position: relative;
  overflow: hidden;
}
</style>
