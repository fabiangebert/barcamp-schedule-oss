<template>
  <div class="space-y-4">
    <div
      v-if="!explanationDismissed"
      class="bg-tertiary/10 border border-tertiary/20 rounded-xl p-4 flex flex-col md:flex-row md:items-center gap-4 mb-4 relative group/explanation"
    >
      <button 
        class="absolute top-3 right-3 text-gray-mid/40 hover:text-primary opacity-0 group-hover/explanation:opacity-100 transition-all duration-300"
        @click="dismissExplanation"
      >
        <CrossIcon class="w-4 h-4" />
      </button>
      <div class="bg-tertiary/20 p-3 rounded-full self-start md:self-center">
        <DocumentEmptyIcon class="w-5 h-5 text-primary" />
      </div>
      <div class="pr-6">
        <h4 class="text-xs font-black uppercase tracking-wider text-primary">
          {{ t('documentation.howToAdd') }}
        </h4>
        <p class="text-sm text-gray-mid/80 mt-1">
          {{ t('documentation.howToAddExplanation') }}
        </p>
      </div>
    </div>

    <div
      class="columns-1 md:columns-2 lg:columns-3 xl:columns-4 gap-4 md:mt-4 space-y-4"
    >
      <div
        v-for="group in groupedDocs"
        :key="group.key"
        :class="[
          'break-inside-avoid block w-full bg-surface rounded-xl border border-gray-light/40 overflow-hidden shadow-md transition-shadow duration-500',
          group.session && 'hover:shadow-lg cursor-pointer'
        ]"
        @click="group.session && $emit('open-session', group.session)"
      >
        <div
          class="bg-surface-sunken px-4 py-2 border-b border-gray-light/40 flex items-center justify-between"
        >
          <div>
            <h3
              class="text-sm font-black text-primary leading-tight uppercase tracking-tight"
            >
              {{
                group.timeslot === 'event'
                  ? t('documentation.eventFeedback')
                  : group.session?.title ||
                    group.items[0]?.session_title ||
                    t('documentation.unknownSession')
              }}
            </h3>
            <p
              class="text-3xs font-black text-gray-mid/50 uppercase tracking-widest mt-0.5"
            >
              <template v-if="group.timeslot === 'event'">
                {{
                  t('documentation.eventFeedbackMetadata') ||
                  t('documentation.eventFeedback')
                }}
              </template>
              <template v-else>
                {{ group.timeslot }} • {{ group.room }}
              </template>
            </p>
          </div>
        </div>

        <div class="px-4 py-3 space-y-3">
          <div
            v-for="doc in visibleItems(group)"
            :key="doc.id"
            class="flex flex-col space-y-1 relative pl-3 border-l-2 border-gray-light/40 group"
          >
            <DocumentationItem :doc="doc" @zoom="$emit('zoom', $event)" />
          </div>
          
          <button 
            v-if="group.items.length > 3" 
            class="w-full py-2 text-4xs font-black uppercase tracking-widest text-gray-mid/40 hover:text-primary transition-colors border-t border-gray-light/20 mt-2"
            @click.stop="toggleGroupExpansion(group.key)"
          >
            {{ expandedGroups.has(group.key) ? t('common.showLess') : t('documentation.showMoreContributions', { count: group.items.length - 3 }) }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref, computed } from 'vue'
import DocumentEmptyIcon from '../icons/DocumentEmptyIcon.vue'
import CrossIcon from '../icons/CrossIcon.vue'
import DocumentationItem from './DocumentationItem.vue'

const props = defineProps({
  docs: { type: Array, required: true },
  schedule: { type: Object, required: true }
})

defineEmits(['open-session', 'zoom'])

const explanationDismissed = ref(localStorage.getItem('documentation_explanation_dismissed') === 'true')
const expandedGroups = ref(new Set())

const dismissExplanation = () => {
  explanationDismissed.value = true
  localStorage.setItem('documentation_explanation_dismissed', 'true')
}

const toggleGroupExpansion = (key) => {
  if (expandedGroups.value.has(key)) {
    expandedGroups.value.delete(key)
  } else {
    expandedGroups.value.add(key)
  }
}

const visibleItems = (group) => {
  if (expandedGroups.value.has(group.key)) {
    return group.items
  }
  return group.items.slice(0, 3)
}

const groupedDocs = computed(() => {
  const groups = {}
  props.docs.forEach((doc) => {
    const isEvent = doc.timeslot === 'event' && doc.room === 'event'
    const key = isEvent ? 'event' : `${doc.timeslot}|${doc.room}`
    if (!groups[key]) {
      groups[key] = {
        key,
        timeslot: doc.timeslot,
        room: doc.room,
        items: [],
        session: isEvent
          ? null
          : props.schedule.data.sessions.find(
              (s) => s.timeslot === doc.timeslot && s.room === doc.room
            )
      }
    }
    groups[key].items.push(doc)
  })
  return Object.values(groups)
})
</script>
