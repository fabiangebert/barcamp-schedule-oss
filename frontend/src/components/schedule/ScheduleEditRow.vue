<template>
  <div v-if="isEditing" class="flex gap-2 mb-4 items-center">
    <BaseButton
      variant="ghost"
      size="dense"
      class="flex items-center gap-1.5! px-2!"
      :title="t('schedule.toggleAdvancedControls')"
      @click="$emit('toggle-advanced-controls')"
    >
      <component
        :is="showAdvancedControls ? EyeIcon : EyeOffIcon"
        class="h-4 w-4"
        :class="showAdvancedControls ? 'text-primary' : 'text-gray-mid'"
      />
      <span>
        {{t('schedule.advancedControls')}}
      </span>
    </BaseButton>

    <div class="w-px h-4 bg-gray-light/40 mx-1"></div>

    <BaseButton
      variant="ghost"
      size="dense"
      @click="$emit('add-timeslot')"
    >
      + {{ t('schedule.addTimeslot') }}
    </BaseButton>
    <BaseButton
      variant="ghost"
      size="dense"
      @click="$emit('add-room')"
    >
      + {{ t('schedule.addRoom') }}
    </BaseButton>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import BaseButton from '../base/BaseButton.vue'
import EyeIcon from '../icons/EyeIcon.vue'
import EyeOffIcon from '../icons/EyeOffIcon.vue'

defineProps({
  isEditing: {
    type: Boolean,
    default: false
  },
  showAdvancedControls: {
    type: Boolean,
    default: true
  }
})

defineEmits(['add-timeslot', 'add-room', 'toggle-advanced-controls'])
</script>
