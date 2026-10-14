<template>
  <div class="md:flex md:justify-end">
    <ScheduleNavigationBar
      :is-editing="isEditing"
      :event-average-rating="eventAverageRating"
      :read-only="readOnly"
      :ask-for-feedback="!!schedule.data?.ask_for_feedback"
      :can-see-docs-overview="!readOnly"
      :total-doc-count="totalDocCount"
      :can-edit="canEdit"
      @open-feedback="emit('open-feedback')"
      @toggle-docs="emit('toggle-docs')"
      @toggle-edit="emit('toggle-edit')"
    />
  </div>
  <div
    :class="{ 'select-none max-md:hidden grid': isEditing }"
    @mousedown="onGridMouseDown"
  >
    <h2
      class="text-3xs font-black uppercase tracking-[0.2em] text-gray-mid/50 px-1 my-2"
    >
      {{ t(isEditing ? 'schedule.editor' : 'schedule.schedule') }}
    </h2>

    <ScheduleEditRow
      :is-editing="isEditing"
      :show-advanced-controls="showAdvancedControls"
      @add-timeslot="addTimeslot"
      @add-room="addRoom"
      @toggle-advanced-controls="showAdvancedControls = !showAdvancedControls"
    />

    <ScheduleTopicBar
      v-if="isEditing"
      :topics="topics"
      :is-editing="isEditing"
      :selected-topic="selectedTopic"
      :active-edit="activeEdit"
      :is-dimmed="isEditing && activeEdit && activeEdit.type !== 'topic'"
      :dragged-topic-index="draggedTopicIndex"
      :drag-over-topic-index="dragOverTopicIndex"
      :show-color-picker="showColorPicker"
      @toggle-filter="toggleTopicFilter"
      @start-editing="startEditing"
      @stop-editing="stopEditing"
      @add-topic="addTopic"
      @update-topic="updateTopic"
      @delete-topic="requestDeleteTopic"
      @dragstart="onDragStart('topic', $event.index, $event.event)"
      @dragend="onDragEnd"
      @dragover="onDragOver('topic', $event.index, $event.event)"
      @dragleave="onDragLeave"
      @drop="onDrop('topic', $event.index, $event.event)"
    />

    <!-- Main Grid -->
    <div
      class="block md:grid w-full border-gray-light/40 md:shadow-md bg-surface"
      :style="gridStyle"
    >
      <!-- Top-left corner -->
      <div
        class="bg-surface-raised border-t border-l border-b border-r border-gray-light/40 md:sticky md:top-0 md:z-30"
        :class="{ 'hidden md:block': !isEditing }"
      ></div>

      <!-- Room headers -->
      <GridHeader
        v-for="(room, index) in rooms"
        :key="index"
        type="room"
        class="md:sticky md:top-0 md:z-30"
        :value="room.name"
        :size="room.size || 'S'"
        :is-editing="isEditing"
        :show-advanced-controls="showAdvancedControls"
        :is-editable="isActiveEdit('room', index) && draggedCol === null"
        :is-dimmed="activeEdit && !isActiveEdit('room', index)"
        :is-dragging="draggedCol === index"
        :is-drag-over="dragOverCol === index"
        :is-dragging-in-progress="draggedCol !== null"
        :drag-direction="
          dragOverCol === index ? (draggedCol > index ? 'left' : 'right') : ''
        "
        :delete-title="t('schedule.deleteRoom')"
        @delete="requestDeleteRoom(index)"
        @update-size="updateRoomSize(index, $event)"
        @start-editing="startEditing('room', index, null, $event)"
        @stop-editing="stopEditing('room', index, null)"
        @update="updateRoom(index, $event)"
        @focus="onFocus"
        @dragstart="onDragStart('column', index, $event)"
        @dragend="onDragEnd"
        @dragover="onDragOver('column', index, $event)"
        @dragleave="onDragLeave"
        @drop="onDrop('column', index, $event)"
      />

      <template
        v-for="(timeslot, timeIndex) in filteredTimeslots"
        :key="timeIndex"
      >
        <!-- Timeslot header -->
        <GridHeader
          type="timeslot"
          :value="timeslot.name"
          :is-hidden="!!timeslot.hidden"
          :is-editing="isEditing"
          :show-advanced-controls="showAdvancedControls"
          :is-editable="
            isActiveEdit('timeslot', timeIndex) && draggedRow === null
          "
          :is-dimmed="activeEdit && !isActiveEdit('timeslot', timeIndex)"
          :is-dragging="draggedRow === timeIndex"
          :is-drag-over="dragOverRow === timeIndex"
          :is-dragging-in-progress="draggedRow !== null"
          :drag-direction="
            dragOverRow === timeIndex
              ? draggedRow > timeIndex
                ? 'before'
                : 'after'
              : ''
          "
          :delete-title="t('schedule.deleteTimeslot')"
          @delete="requestDeleteTimeslot(timeIndex)"
          @toggle-hidden="toggleTimeslotHidden(timeIndex)"
          @start-editing="startEditing('timeslot', timeIndex, null, $event)"
          @stop-editing="stopEditing('timeslot', timeIndex, null)"
          @update="updateTimeslot(timeIndex, $event)"
          @focus="onFocus"
          @dragstart="onDragStart('row', timeIndex, $event)"
          @dragend="onDragEnd"
          @dragover="onDragOver('row', timeIndex, $event)"
          @dragleave="onDragLeave"
          @drop="onDrop('row', timeIndex, $event)"
        />

        <!-- Session cells -->
        <SessionCell
          v-for="(room, roomIndex) in rooms"
          :id="`${timeIndex}-${roomIndex}`"
          :key="`${timeIndex}-${roomIndex}`"
          :session="getSession(timeslot.name, room.name)"
          :timeslot="timeslot.name"
          :room="room.name"
          :room-size="room.size || 'S'"
          :time-index="timeIndex"
          :room-index="roomIndex"
          :is-dragging-session="isDraggingSession || draggedTopicIndex !== null"
          :is-dimmed="
            store.isDimmed(
              timeIndex,
              roomIndex,
              timeslot.name,
              room.name,
              selectedTopic
            )
          "
          :is-dragging="
            isDraggingSession &&
            dragOverSession?.timeslot === timeslot.name &&
            dragOverSession?.room === room.name
          "
          :is-drag-over="
            dragOverSession &&
            dragOverSession.timeslot === timeslot.name &&
            dragOverSession.room === room.name
          "
          :is-dragging-relative="
            isEditing && (draggedRow === timeIndex || draggedCol === roomIndex)
          "
          :topics="topics"
          :default-values="defaultValues"
          :doc-count="
            documentationCounts.find(
              (c) => c.timeslot === timeslot.name && c.room === room.name
            )?.count || 0
          "
          @toggle-audience-interest="
            toggleAudienceInterest(timeslot.name, room.name)
          "
          @click="emit('open-documentation', $event)"
          @start-editing="startEditing"
          @stop-editing="stopEditing"
          @update-session="updateSessionField"
          @delete-session="requestDeleteSession(timeslot.name, room.name)"
          @focus="onFocus"
          @navigate="navigateNextField"
          @dragstart="
            onDragStart(
              'session',
              { timeslot: timeslot.name, room: room.name },
              $event
            )
          "
          @dragend="onDragEnd"
          @dragover="
            onDragOver(
              'session',
              { timeslot: timeslot.name, room: room.name },
              $event
            )
          "
          @dragleave="onDragLeave"
          @drop="
            onDrop(
              'session',
              { timeslot: timeslot.name, room: room.name },
              $event
            )
          "
        />
      </template>
    </div>

    <!-- Feedback notification for mobile -->
    <FeedbackNotification
      v-if="!!schedule.data?.ask_for_feedback && !isEditing && !readOnly"
      :schedule-id="schedule.id"
      :event-name="schedule.name"
      @open-feedback="emit('open-feedback')"
    />

    <!-- Footer for Editor -->

    <ConfirmationModal
      :visible="showConfirm"
      :title="confirmTitle"
      :message="confirmMessage"
      :confirm-text="t('common.delete')"
      :cancel-text="t('common.cancel')"
      @confirm="onConfirm"
      @cancel="onCancel"
    />
  </div>

  <div
    class="hidden"
    :class="{
      'max-md:flex rounded inset-shadow-sm inset-shadow-gray-light/40 border-2 border-gray-light/40 bg-surface-raised items-center justify-center min-h-50':
        isEditing
    }"
  >
    <div>{{ t('schedule.screenTooSmall') }}</div>
  </div>
</template>

<script setup>
import { computed, ref, toRefs, watch } from 'vue'
import { useI18n } from 'vue-i18n'

import { useScheduleStore } from '../../stores/schedule'
import { useSchedule } from '../../composables/useSchedule'
import { useGridEditing } from '../../composables/useGridEditing'
import { useGridDragAndDrop } from '../../composables/useGridDragAndDrop'
import ConfirmationModal from '../modals/ConfirmationModal.vue'
import ScheduleTopicBar from './ScheduleTopicBar.vue'
import ScheduleNavigationBar from './ScheduleNavigationBar.vue'
import ScheduleEditRow from './ScheduleEditRow.vue'
import GridHeader from './GridHeader.vue'
import SessionCell from './SessionCell.vue'
import FeedbackNotification from '../common/FeedbackNotification.vue'

const { t } = useI18n()

const store = useScheduleStore()

const props = defineProps({
  schedule: {
    type: Object,
    required: true
  },
  isEditing: {
    type: Boolean,
    default: false
  },
  documentationCounts: {
    type: Array,
    default: () => []
  },
  readOnly: {
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

// Sync props with store
watch(
  () => props.schedule,
  (newVal) => store.setSchedule(newVal),
  { immediate: true }
)
watch(
  () => props.isEditing,
  (newVal) => (store.isEditing = newVal),
  { immediate: true }
)
watch(
  () => props.readOnly,
  (newVal) => (store.readOnly = newVal),
  { immediate: true }
)
const showAdvancedControls = computed({
  get: () => store.showAdvancedControls,
  set: (val) => (store.showAdvancedControls = val)
})

const emit = defineEmits([
  'update:schedule',
  'save',
  'open-documentation',
  'open-feedback',
  'toggle-docs',
  'toggle-edit'
])

const { schedule } = toRefs(props)
const { rooms, timeslots, topics, unassignedSessions, getSession, defaultValues } =
  useSchedule(schedule)

const updateSchedule = (newData) => {
  const updated = {
    ...props.schedule,
    ...newData,
    data: {
      ...props.schedule.data,
      ...(newData.data || {})
    }
  }
  // Ensure we don't duplicate data if it was passed at top level
  if (newData.rooms) updated.data.rooms = newData.rooms
  if (newData.timeslots) updated.data.timeslots = newData.timeslots
  if (newData.sessions) updated.data.sessions = newData.sessions
  if (newData.topics) updated.data.topics = newData.topics

  emit('update:schedule', updated)
  emit('save')
}

const {
  activeEdit,
  startEditing,
  stopEditing,
  navigateNextField,
  onFocus,
  onGridMouseDown,
  isActiveEdit
} = useGridEditing(
  toRefs(props).isEditing,
  rooms,
  timeslots,
  unassignedSessions
)

const {
  draggedRow,
  dragOverRow,
  draggedCol,
  dragOverCol,
  draggedTopicIndex,
  dragOverTopicIndex,
  isDraggingSession,
  dragOverSession,
  onDragStart,
  onDragEnd,
  onDragOver,
  onDragLeave,
  onDrop
} = useGridDragAndDrop(
  toRefs(props).isEditing,
  rooms,
  timeslots,
  topics,
  updateSchedule,
  toRefs(props),
  defaultValues
)

// Topic Filter state
const selectedTopic = ref(null)

const getUniqueName = (base, existing) => {
  let name = base
  let counter = 1
  while (existing.includes(name)) {
    name = `${base} ${++counter}`
  }
  return name
}

const eventAverageRating = computed(() => {
  const eventDoc = props.documentationCounts.find(
    (d) => d.timeslot === 'event' && d.room === 'event'
  )
  if (!eventDoc || !eventDoc.avg_rating) return null
  return parseFloat(eventDoc.avg_rating).toFixed(1)
})

const toggleTopicFilter = (topic) => {
  if (props.isEditing) return
  if (selectedTopic.value === topic) {
    selectedTopic.value = null
  } else {
    selectedTopic.value = topic
  }
}

// Escape key to stop editing
const showColorPicker = ref(false)

watch(activeEdit, (newEdit) => {
  showColorPicker.value =
    newEdit?.type === 'topic' && newEdit?.id === null && newEdit?.field === 'new'
})

// Confirmation modal state
const showConfirm = ref(false)
const confirmTitle = ref('Confirm')
const confirmMessage = ref('')
const pendingAction = ref(null)

const askConfirm = (title, message, action) => {
  confirmTitle.value = title
  confirmMessage.value = message
  pendingAction.value = action
  showConfirm.value = true
}

const onConfirm = () => {
  if (pendingAction.value) pendingAction.value()
  showConfirm.value = false
  pendingAction.value = null
}

const onCancel = () => {
  showConfirm.value = false
  pendingAction.value = null
}

const filteredTimeslots = computed(() => {
  if (props.isEditing) return timeslots.value
  return timeslots.value.filter((t) => !t.hidden)
})

const gridStyle = computed(() => ({
  gridTemplateColumns: `minmax(80px, 0.15fr) repeat(${rooms.value.length}, minmax(0, 1fr))`
}))

const requestDeleteSession = (timeslot, room) => {
  const sessions = props.schedule.data.sessions || []
  let session
  session = sessions.find((s) => s.timeslot === timeslot && s.room === room)
  const title = session?.title || defaultValues.title
  askConfirm(
    t('schedule.deleteSession'),
    t('schedule.deleteSessionConfirm', { name: title }),
    () => deleteSession(timeslot, room)
  )
}

const deleteSession = (timeslot, room) => {
  const sessions = props.schedule.data.sessions || []
  const sessionIdx = sessions.findIndex(
    (s) => s.timeslot === timeslot && s.room === room
  )
  if (sessionIdx > -1) {
    const newSessions = [...sessions]
    newSessions.splice(sessionIdx, 1)
    updateSchedule({ sessions: newSessions })
  }
}

const requestDeleteTimeslot = (index) => {
  const name = timeslots.value[index].name
  askConfirm(
    t('schedule.deleteTimeslot'),
    t('schedule.deleteTimeslotConfirm', { name: name }),
    () => deleteTimeslot(index)
  )
}

const deleteTimeslot = (index) => {
  const oldValue = timeslots.value[index]
  const timeslotName = oldValue.name
  const newTimeslots = [...timeslots.value]
  newTimeslots.splice(index, 1)
  const newSessions = props.schedule.data.sessions.filter(
    (s) => s.timeslot !== timeslotName
  )
  updateSchedule({ timeslots: newTimeslots, sessions: newSessions })
}

const requestDeleteRoom = (index) => {
  const name = rooms.value[index].name
  askConfirm(
    t('schedule.deleteRoom'),
    t('schedule.deleteRoomConfirm', { name: name }),
    () => deleteRoom(index)
  )
}

const deleteRoom = (index) => {
  const room = rooms.value[index]
  const oldValue = room.name
  const newRooms = [...rooms.value]
  newRooms.splice(index, 1)
  const newSessions = props.schedule.data.sessions.filter(
    (s) => s.room !== oldValue
  )
  updateSchedule({ rooms: newRooms, sessions: newSessions })
}

const addTopic = (payload = null) => {
  if (payload === null) {
    if (showColorPicker.value) {
      stopEditing('topic', null, 'new')
    } else {
      startEditing('topic', null, 'new')
    }
    return
  }
  stopEditing('topic', null, 'new')

  const { name: requestedName, colorIndex } = payload
  const existingNames = topics.value.map((t) => t.name)
  const name =
    requestedName || getUniqueName(t('schedule.topicName'), existingNames)

  const newTopics = [
    ...(props.schedule.data.topics || []),
    { name, colorIndex }
  ]
  updateSchedule({ topics: newTopics })
}

const updateTopic = (oldName, values) => {
  const { name: requestedName, colorIndex } = values
  const trimmedValue = requestedName.trim()
  if (trimmedValue === '') {
    return
  }
  let finalName = trimmedValue
  const existingNames = topics.value.map((t) => t.name)
  const otherTopicNames = existingNames.filter((t) => t !== oldName)
  if (otherTopicNames.includes(trimmedValue)) {
    finalName = getUniqueName(trimmedValue, otherTopicNames)
  }

  let newTopics = [...(props.schedule.data.topics || [])]
  const idx = newTopics.findIndex((t) => t.name === oldName)

  if (idx > -1) {
    newTopics[idx] = { name: finalName, colorIndex }
  } else {
    newTopics.push({ name: finalName, colorIndex })
  }

  const newSessions = props.schedule.data.sessions.map((s) =>
    s.topic === oldName ? { ...s, topic: finalName } : s
  )
  updateSchedule({ topics: newTopics, sessions: newSessions })
}

const addRoom = () => {
  const roomNames = rooms.value.map((r) => r.name)
  const name = getUniqueName(t('schedule.newRoom'), roomNames)
  const newRooms = [...rooms.value, { name, size: 'S' }]
  updateSchedule({ rooms: newRooms })
}

const addTimeslot = () => {
  const timeslotNames = timeslots.value.map((t) => t.name)
  const name = getUniqueName(t('schedule.newTimeslot'), timeslotNames)
  const newTimeslots = [...timeslots.value, { name }]
  updateSchedule({ timeslots: newTimeslots })
}

const updateRoom = (index, event) => {
  const newValue = event.target.innerText.trim()
  const room = rooms.value[index]
  const oldValue = room.name
  if (newValue === oldValue) return
  if (newValue === '') {
    event.target.innerText = oldValue
    return
  }
  let finalValue = newValue
  const roomNames = rooms.value.map((r) => r.name)
  if (roomNames.some((r, i) => i !== index && r === newValue)) {
    finalValue = getUniqueName(
      newValue,
      roomNames.filter((_, i) => i !== index)
    )
    event.target.innerText = finalValue
  }
  const newRooms = [...rooms.value]
  newRooms[index] = { ...room, name: finalValue }
  const newSessions = props.schedule.data.sessions.map((s) =>
    s.room === oldValue ? { ...s, room: finalValue } : s
  )
  updateSchedule({ rooms: newRooms, sessions: newSessions })
}

const updateRoomSize = (index, size) => {
  const newRooms = [...rooms.value]
  const room = rooms.value[index]
  newRooms[index] = { ...room, size }
  updateSchedule({ rooms: newRooms })
}

const updateTimeslot = (index, event) => {
  const newValue = event.target.innerText.trim()
  const timeslot = timeslots.value[index]
  const oldValue = timeslot.name
  if (newValue === oldValue) return
  if (newValue === '') {
    event.target.innerText = oldValue
    return
  }
  let finalValue = newValue
  const timeslotNames = timeslots.value.map((t) => t.name)
  if (timeslotNames.some((t, i) => i !== index && t === newValue)) {
    finalValue = getUniqueName(
      newValue,
      timeslotNames.filter((_, i) => i !== index)
    )
    event.target.innerText = finalValue
  }
  const newTimeslots = [...timeslots.value]
  newTimeslots[index] = { ...timeslot, name: finalValue }
  const newSessions = props.schedule.data.sessions.map((s) =>
    s.timeslot === oldValue ? { ...s, timeslot: finalValue } : s
  )
  updateSchedule({ timeslots: newTimeslots, sessions: newSessions })
}

const toggleTimeslotHidden = (index) => {
  const newTimeslots = [...timeslots.value]
  const timeslot = timeslots.value[index]
  newTimeslots[index] = { ...timeslot, hidden: !timeslot.hidden }
  updateSchedule({ timeslots: newTimeslots })
}

const updateSessionField = (timeslot, room, field, event) => {
  const newValue = (
    field === 'topic' || field === 'audience_interest'
      ? event.target.value
      : event.target.innerText
  ).trim()
  const sessions = props.schedule.data.sessions || []
  let currentSession = getSession(timeslot, room)
  let sessionIdx = sessions.findIndex(
    (s) => s.timeslot === timeslot && s.room === room
  )

  if (currentSession && currentSession[field] === newValue) return
  if (!currentSession && defaultValues[field] === newValue) return
  if (newValue === '' && !currentSession) return
  let newSessions = [...sessions]
  if (sessionIdx > -1) {
    newSessions[sessionIdx] = { ...newSessions[sessionIdx], [field]: newValue }
  } else {
    const newSession = {
      timeslot: timeslot,
      room: room,
      title: defaultValues.title,
      topic: defaultValues.topic,
      [field]: newValue
    }
    newSessions.push(newSession)
  }
  updateSchedule({ sessions: newSessions })
}

const toggleAudienceInterest = (timeslot, room) => {
  let currentSession = getSession(timeslot, room)

  const currentInterest = currentSession?.audience_interest || 'S'
  const nextValues = { S: 'M', M: 'L', L: 'S' }
  const nextInterest = nextValues[currentInterest] || 'S'

  updateSessionField(timeslot, room, 'audience_interest', {
    target: { value: nextInterest }
  })
}

const requestDeleteTopic = (topicName) => {
  askConfirm(
    t('schedule.deleteTopic'),
    t('schedule.deleteTopicConfirm', { name: topicName }),
    () => {
      deleteTopic(topicName)
      stopEditing('topic', topicName, 'color')
    }
  )
}

const deleteTopic = (topicName) => {
  const newTopics = (props.schedule.data.topics || []).filter((t) => {
    return t.name !== topicName
  })
  const newSessions = props.schedule.data.sessions.map((s) =>
    s.topic === topicName ? { ...s, topic: defaultValues.topic } : s
  )
  updateSchedule({ topics: newTopics, sessions: newSessions })
}
</script>
