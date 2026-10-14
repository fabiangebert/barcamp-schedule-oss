<template>
  <div class="flex gap-2 my-2">
    <BaseButton
      v-if="canEdit"
      variant="primary-outline"
      size="dense"
      @click="$emit('toggle-edit')"
    >
      {{ isEditing ? t('common.view') : t('common.edit') }}
    </BaseButton>
    <BaseButton
      v-if="!isEditing && canSeeDocsOverview"
      variant="primary-outline"
      size="dense"
      @click="$emit('toggle-docs')"
    >
      <div class="flex items-center gap-2">
        <span>
          {{ t('schedule.documentation') }}
        </span>
        <span
          v-if="totalDocCount > 0"
          class="flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-tertiary text-primary text-4xs font-black border border-primary/10 shadow-sm"
        >
          {{ totalDocCount }}
        </span>
      </div>
    </BaseButton>
    <BaseButton
      v-if="!isEditing && !readOnly && askForFeedback"
      variant="secondary"
      size="dense"
      @click="$emit('open-feedback')"
    >
      <div class="flex items-center gap-2">
        <span>{{ t('documentation.eventFeedback') }}</span>
        <span
          v-if="eventAverageRating"
          class="flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-surface-inverse text-white text-4xs font-black border border-secondary/10 shadow-sm"
          :title="
            t('documentation.rating') + ': ' + eventAverageRating + '/10'
          "
        >
          {{ eventAverageRating }}
        </span>
      </div>
    </BaseButton>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import BaseButton from '../base/BaseButton.vue'

defineProps({
  isEditing: {
    type: Boolean,
    default: false
  },
  eventAverageRating: {
    type: [String, Number],
    default: null
  },
  readOnly: {
    type: Boolean,
    default: false
  },
  askForFeedback: {
    type: Boolean,
    default: false
  },
  canSeeDocsOverview: {
    type: Boolean,
    default: false
  },
  totalDocCount: {
    type: Number,
    default: 0
  },
  canEdit: {
    type: Boolean,
    default: false
  }
})

defineEmits(['open-feedback', 'toggle-docs', 'toggle-edit'])
</script>
