<template>
  <div
    class="group relative block md:flex items-center justify-center bg-surface-raised p-3 text-2xs font-black text-gray-mid border-b border-r border-gray-light/40 transition-all min-w-0"
    :class="{
      'md:border-t': type === 'room',
      'md:border-l': type === 'timeslot',
      'bg-primary/10': isEditing && isDragging,
      'border-t-4 border-t-primary':
        isEditing && isDragOver && dragDirection === 'before',
      'border-b-4 border-b-primary':
        isEditing && isDragOver && dragDirection === 'after',
      'border-l-4 border-l-primary':
        isEditing && isDragOver && dragDirection === 'left',
      'border-r-4 border-r-primary':
        isEditing && isDragOver && dragDirection === 'right',
      'md:p-4 text-center uppercase tracking-[0.15em]': type === 'room',
      'max-md:hidden!': type === 'room',
      'max-md:mb-3 max-md:rounded-md max-md:border-t max-md:border-l max-md:text-primary/70 max-md:bg-surface-raised':
        type === 'timeslot'
    }"
    :draggable="isEditing && !isEditable"
    @dragstart="!isEditable && $emit('dragstart', $event)"
    @dragend="$emit('dragend', $event)"
    @dragover.prevent="$emit('dragover', $event)"
    @dragleave="$emit('dragleave', $event)"
    @drop="$emit('drop', $event)"
  >
    <BaseButton
      v-if="isEditing"
      variant="ghost"
      class="absolute right-1.5 top-1.5 hidden! h-4 w-4 p-0! items-center justify-center rounded-full bg-secondary! text-white! hover:bg-secondary/80! group-hover:flex! focus-within:flex! focus:outline-none focus:ring-1 focus:ring-secondary/20 z-20 shadow-md cursor-pointer transition-all"
      :title="deleteTitle"
      @click="$emit('delete')"
    >
      <CrossIcon class="h-3 w-3" />
    </BaseButton>

    <BaseButton
      v-if="isEditing && type === 'timeslot'"
      variant="ghost"
      class="absolute right-1.5 bottom-5.5 h-4 w-4 p-0! items-center justify-center rounded bg-primary/20 text-primary hover:bg-primary/30 z-20 cursor-pointer transition-all"
      :class="{
        'hidden! group-hover:flex! ': !showAdvancedControls,
        'flex!': showAdvancedControls
      }"
      :title="
        isHidden ? t('schedule.showTimeslot') : t('schedule.hideTimeslot')
      "
      @click="$emit('toggle-hidden')"
    >
      <component :is="isHidden ? EyeOffIcon : EyeIcon" class="h-3 w-3" />
    </BaseButton>

    <BaseButton
      v-if="isEditing && type === 'room'"
      variant="ghost"
      class="absolute right-1.5 bottom-1.5 h-4 w-4 p-0! items-center justify-center rounded bg-primary/20 text-primary hover:bg-primary/30 z-20 cursor-pointer transition-all text-4xs font-black"
      :class="{
        'hidden! group-hover:flex! ': !showAdvancedControls,
        'flex!': showAdvancedControls
      }"
      :title="t('schedule.roomSize')"
      @click="
        $emit('update-size', size === 'S' ? 'M' : size === 'M' ? 'L' : 'S')
      "
    >
      {{ size }}
    </BaseButton>

    <span
      :contenteditable="isEditing && isEditable && !isDraggingInProgress"
      class="rounded-md border border-transparent px-2 py-1 transition-all"
      :class="{
        'cursor-pointer hover:bg-white/50': isEditing,
        'focus:text-base focus:cursor-auto focus:border-primary focus:bg-surface focus:outline-none':
          isEditing,
        'opacity-50': isEditing && isDimmed && !isEditable
      }"
      @click="isEditing && $emit('start-editing', $event)"
      @focus="$emit('focus', $event)"
      @keydown.enter.prevent="$event.target.blur()"
      @blur="handleBlur($event)"
    >
      {{ value }}
    </span>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import BaseButton from '../base/BaseButton.vue'
import CrossIcon from '../icons/CrossIcon.vue'
import EyeIcon from '../icons/EyeIcon.vue'
import EyeOffIcon from '../icons/EyeOffIcon.vue'

defineProps({
  type: {
    type: String,
    required: true // 'room' or 'timeslot'
  },
  value: {
    type: String,
    required: true
  },
  isEditing: {
    type: Boolean,
    default: false
  },
  isEditable: {
    type: Boolean,
    default: false
  },
  isDimmed: {
    type: Boolean,
    default: false
  },
  isDragging: {
    type: Boolean,
    default: false
  },
  isDragOver: {
    type: Boolean,
    default: false
  },
  isDraggingInProgress: {
    type: Boolean,
    default: false
  },
  dragDirection: {
    type: String,
    default: '' // 'before', 'after' (for timeslot/rows) or 'left', 'right' (for rooms/cols)
  },
  deleteTitle: {
    type: String,
    default: ''
  },
  size: {
    type: String,
    default: 'S'
  },
  showAdvancedControls: {
    type: Boolean,
    default: true
  },
  isHidden: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'delete',
  'toggle-hidden',
  'update-size',
  'start-editing',
  'stop-editing',
  'update',
  'focus',
  'dragstart',
  'dragend',
  'dragover',
  'dragleave',
  'drop'
])

const handleBlur = (event) => {
  emit('update', event)
  emit('stop-editing')
}
</script>
