<template>
  <h3
    class="text-3xs font-black uppercase tracking-[0.2em] text-gray-mid/50 px-1 mt-2"
  >
    {{ t('schedule.topics') }}
  </h3>
  <div
    class="flex flex-wrap items-center gap-2 px-1 mb-2"
    :class="{
      'opacity-30 grayscale-[0.5]': isDimmed && activeEdit?.type !== 'topic'
    }"
  >
    <TopicBadge
      v-for="(topic, index) in topics"
      :key="topic.name"
      :topic="topic"
      :is-editing="isEditing"
      :is-selected="!isEditing && selectedTopic === topic.name"
      :is-dimmed="!isEditing && selectedTopic && selectedTopic !== topic.name"
      :is-editing-color="
        isEditing &&
        activeEdit?.type === 'topic' &&
        activeEdit?.id === topic.name &&
        activeEdit?.field === 'color'
      "
      :is-dragging="isEditing && draggedTopicIndex === index"
      :is-drag-over="isEditing && dragOverTopicIndex === index"
      :drag-direction="getDragDirection(index)"
      @click="onTopicClick(topic)"
      @dragstart="$emit('dragstart', { index, event: $event })"
      @dragend="$emit('dragend', $event)"
      @dragover.prevent="$emit('dragover', { index, event: $event })"
      @dragleave="$emit('dragleave', $event)"
      @drop="$emit('drop', { index, event: $event })"
      @update-topic="$emit('update-topic', topic.name, $event)"
      @stop-editing="$emit('stop-editing', 'topic', topic.name, 'color')"
      @delete="$emit('delete-topic', topic.name)"
    />

    <div class="relative">
      <BaseButton
        v-if="isEditing"
        variant="ghost"
        size="dense"
        :title="t('schedule.addNewTopic')"
        @click="onAddNewTopicClick"
      >
        +
      </BaseButton>

      <!-- Color Picker Popover for NEW topic -->
      <TopicEditorPopover
        v-if="showColorPicker"
        name=""
        :color-index="0"
        @confirm="onAddTopicConfirm"
        @cancel="$emit('stop-editing', 'topic', null, 'new')"
        @click.stop
      />
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import TopicBadge from './TopicBadge.vue'
import BaseButton from '../base/BaseButton.vue'
import TopicEditorPopover from './TopicEditorPopover.vue'

const props = defineProps({
  topics: {
    type: Array,
    required: true
  },
  isEditing: {
    type: Boolean,
    default: false
  },
  selectedTopic: {
    type: String,
    default: null
  },
  activeEdit: {
    type: Object,
    default: null
  },
  isDimmed: {
    type: Boolean,
    default: false
  },
  draggedTopicIndex: {
    type: Number,
    default: null
  },
  dragOverTopicIndex: {
    type: Number,
    default: null
  },
  showColorPicker: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits([
  'toggle-filter',
  'start-editing',
  'stop-editing',
  'add-topic',
  'update-topic',
  'delete-topic',
  'dragstart',
  'dragend',
  'dragover',
  'dragleave',
  'drop'
])

const onAddTopicConfirm = (values) => {
  if (typeof values.colorIndex !== 'number' || values.colorIndex < 0) {
    return
  }
  emit('add-topic', { name: values.name.trim(), colorIndex: values.colorIndex })
}

const onTopicClick = (topic) => {
  if (props.isEditing) {
    emit('start-editing', 'topic', topic.name, 'color')
  } else {
    emit('toggle-filter', topic.name)
  }
}

const onAddNewTopicClick = () => {
  emit('start-editing', 'topic', null, 'new')
}

const getDragDirection = (index) => {
  if (props.dragOverTopicIndex !== index) return ''
  return props.draggedTopicIndex > index ? 'left' : 'right'
}
</script>
