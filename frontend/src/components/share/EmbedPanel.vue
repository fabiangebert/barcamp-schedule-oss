<template>
  <div>
    <div class="mt-4 border-t border-gray-light/30 pt-6">
      <h4 class="text-sm font-bold text-gray-mid uppercase tracking-wider mb-4">
        {{ t('modals.embedTitle') }}
      </h4>
      <p class="text-xs text-gray-mid mb-3">
        {{ t('modals.embedDescription') }}
      </p>

      <div class="relative group">
        <pre
          class="bg-surface-sunken border border-gray-light/50 rounded-lg p-4 text-3xs font-mono text-gray-mid overflow-x-auto"
        ><code>{{ embedSnippet }}</code></pre>
        <BaseButton
          variant="ghost"
          class="!absolute top-2 right-2 !px-2 !py-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/80"
          @click="copyToClipboard(embedSnippet)"
        >
          {{ t('modals.copy') }}
        </BaseButton>
      </div>
    </div>

    <div class="mt-6 border border-gray-light/50 rounded-lg overflow-hidden">
      <button
        class="w-full flex items-center justify-between px-4 py-2 bg-surface-sunken hover:bg-gray-bright/40 transition-colors text-sm font-bold text-gray-mid uppercase tracking-wider"
        @click="showEmbedPreview = !showEmbedPreview"
      >
        <span>{{ t('modals.embedPreview') }}</span>
        <ChevronDownIcon v-if="!showEmbedPreview" class="h-4 w-4" />
        <ChevronUpIcon v-else class="h-4 w-4" />
      </button>
      <div
        v-if="showEmbedPreview"
        class="p-4 bg-surface border-t border-gray-light/50 h-[400px] overflow-y-auto"
      >
        <barcamp-schedule
          :schedule-id="props.scheduleId"
          :api-base="apiBase"
        ></barcamp-schedule>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { API_BASE } from '../../constants'
import BaseButton from '../base/BaseButton.vue'
import ChevronDownIcon from '../icons/ChevronDownIcon.vue'
import ChevronUpIcon from '../icons/ChevronUpIcon.vue'

const { t } = useI18n()

onMounted(() => {
  // Ensure the web component is registered for the preview
  import('../../web-component-entry.js').catch((err) => {
    console.error('Failed to load web component entry:', err)
  })
})

const props = defineProps({
  scheduleId: { type: String, required: true },
  showNotification: { type: Function, default: null }
})

const showEmbedPreview = ref(false)
const apiBase = API_BASE

// embed.js is served next to index.html by the edge server hosting the app,
// not by the API, so derive its URL from the current app location.
const appBase = computed(() => {
  const { origin, pathname } = window.location
  const path = pathname.replace(/index\.html$/, '').replace(/\/$/, '')
  return `${origin}${path}`
})

const embedSnippet = computed(() => {
  return (
    `<!-- Include script tag once -->
<script type="module" src="${appBase.value}/embed.js"></scr` +
    `ipt>

<!-- Place where you want the schedule to appear -->
<barcamp-schedule
  schedule-id="${props.scheduleId}"
  api-base="${apiBase}"
  locale="auto"
  hide-mobile="false"
  hide-desktop="false"
></barcamp-schedule>
`
  )
})

const copyToClipboard = (text) => {
  navigator.clipboard.writeText(text).then(() => {
    if (props.showNotification) {
      props.showNotification(
        t('modals.linkCopied'),
        t('modals.linkCopiedMessage'),
        'success'
      )
    }
  })
}
</script>
