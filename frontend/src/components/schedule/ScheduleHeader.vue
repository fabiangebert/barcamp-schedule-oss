<template>
  <div
    class="mb-4 flex flex-wrap items-center justify-between border-b border-gray-light/50 pb-2"
  >
    <h2
      class="text-xl max-md:text-lg font-bold text-primary tracking-tight flex items-center justify-between gap-2"
      :class="{
        'group/settings cursor-pointer hover:bg-surface-raised rounded-lg px-2 -ml-2 transition-colors flex items-center gap-2':
          isEditing
      }"
      @click="isEditing && (showSettingsModal = true)"
    >
      <span
        v-if="isEditing"
        class="p-1.5 rounded bg-primary/5 text-primary group-hover/settings:bg-surface-inverse group-hover/settings:text-white transition-colors"
      >
        <PencilIcon class="size-4" />
      </span>
      <span>{{ schedule.name }}</span>
      <span
        v-if="schedule.date || isEditing"
        class="text-sm max-md:text-xs font-semibold text-gray-mid bg-surface-raised px-2 py-0.5 rounded-full whitespace-nowrap"
      >
        {{ schedule.date || t('schedule.noDateSet') }}
      </span>
    </h2>

    <div class="flex flex-wrap justify-end items-center gap-2">
      <!-- Tools -->
      <div v-if="isUserOrAdmin" class="flex items-center gap-2">
        <div
          v-if="isEditing"
          class="flex items-center gap-2 mr-2 pr-2 border-r border-gray-light/30"
        >
          <label
            class="flex items-center gap-2 cursor-pointer select-none group"
          >
            <span
              class="text-xs font-semibold text-gray-mid group-hover:text-primary transition-colors"
            >
              {{ t('schedule.askForFeedback') }}
            </span>
            <span class="relative inline-flex items-center">
              <input
                type="checkbox"
                class="sr-only peer"
                :checked="!!schedule.data?.ask_for_feedback"
                @change="onToggleFeedback"
              />
              <span
                class="w-8 h-4 bg-gray-light/50 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:bg-white after:rounded-full after:h-3 after:w-3 after:transition-all peer-checked:bg-surface-inverse"
              ></span>
            </span>
          </label>
        </div>
      </div>
    </div>
  </div>

  <ScheduleSettingsModal
    :visible="showSettingsModal"
    :name="schedule.name"
    :date="schedule.date"
    :feedback-questions="schedule.data?.feedback_questions ?? []"
    @close="showSettingsModal = false"
    @confirm="onConfirmSettings"
  />
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref } from 'vue'
import PencilIcon from '../icons/PencilIcon.vue'
import ScheduleSettingsModal from './ScheduleSettingsModal.vue'

const props = defineProps({
  schedule: { type: Object, required: true },
  isUserOrAdmin: { type: Boolean, default: false },
  canEdit: { type: Boolean, default: false },
  isEditing: { type: Boolean, default: false },
  totalDocCount: { type: Number, default: 0 }
})

const emit = defineEmits([
  'update:schedule',
  'save',
  'open-feedback'
])

const showSettingsModal = ref(false)

const onConfirmSettings = ({ name, date, feedbackQuestions }) => {
  const newSchedule = {
    ...props.schedule,
    name,
    date,
    data: {
      ...(props.schedule.data ?? {}),
      feedback_questions: feedbackQuestions
    }
  }
  emit('update:schedule', newSchedule)
  emit('save')
  showSettingsModal.value = false
}

const onToggleFeedback = (e) => {
  const newSchedule = { ...props.schedule }
  if (!newSchedule.data) newSchedule.data = {}
  newSchedule.data.ask_for_feedback = e.target.checked
  emit('update:schedule', newSchedule)
}
</script>
