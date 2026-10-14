<template>
  <div
    class="relative rounded-md px-2 py-0.5 text-3xs font-bold shadow-sm uppercase tracking-wider transition-all border border-transparent"
    :class="{
      [`topic-${topic.colorIndex}`]: true,
      'cursor-pointer hover:shadow-md': true,
      'border-primary! border-l-4!':
        isEditing && isDragOver && dragDirection === 'left',
      'border-primary! border-r-4!':
        isEditing && isDragOver && dragDirection === 'right',
      'opacity-50': (isEditing && isDragging) || (!isEditing && isDimmed),
      'ring-2 ring-primary ring-offset-2': !isEditing && isSelected,
      'ring-2 ring-primary ring-offset-1 z-[60]': isEditing && isEditingColor
    }"
    :draggable="isEditing && !isEditingColor"
    @click="$emit('click', $event)"
    @dragstart="!isEditingColor && $emit('dragstart', $event)"
    @dragend="$emit('dragend', $event)"
    @dragover.prevent="$emit('dragover', $event)"
    @dragleave="$emit('dragleave', $event)"
    @drop="$emit('drop', $event)"
  >
    <slot>{{ topic.name }}</slot>

    <!-- Color & Name Picker Popover -->
    <TopicEditorPopover
      v-if="isEditing && isEditingColor"
      :name="topic.name"
      :color-index="topic.colorIndex"
      show-delete
      @confirm="onConfirm"
      @delete="$emit('delete')"
      @cancel="$emit('stop-editing')"
      @click.stop
    />
  </div>
</template>

<script setup>
import TopicEditorPopover from './TopicEditorPopover.vue'

defineProps({
  topic: {
    type: Object,
    required: true
  },
  isEditing: {
    type: Boolean,
    default: false
  },
  isSelected: {
    type: Boolean,
    default: false
  },
  isDimmed: {
    type: Boolean,
    default: false
  },
  isEditingColor: {
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
  dragDirection: {
    type: String,
    default: '' // 'left' or 'right'
  }
})

const emit = defineEmits([
  'click',
  'dragstart',
  'dragend',
  'dragover',
  'dragleave',
  'drop',
  'update-topic',
  'stop-editing',
  'delete'
])

const onConfirm = (values) => {
  emit('update-topic', values)
  emit('stop-editing')
}
</script>
