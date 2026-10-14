<template>
  <div
    :id="id"
    class="group relative min-h-35 rounded-lg md:rounded-none mt-2 mb-3 md:m-0 p-4 border md:border-t-0 md:border-l-0 border-gray-light/40 flex flex-col gap-3 overflow-clip transition-colors"
    :class="{
      'cursor-pointer hover:bg-surface-sunken':
        !isEditing && session && !readOnly,
      'hidden md:block bg-surface-sunken pointer-events-none': !isEditing && !session,
      'bg-surface': session,
      'bg-surface-sunken inset-shadow-sm': !session && isEditing,
      'bg-accent/20! border-l border-t':
        isEditing && (isActiveEdit(null) || isDraggingRelative),
      'rounded-md ring-2 ring-inset ring-primary z-10 shadow-lg bg-surface':
        isEditing && isDragOver,
      'opacity-50': isEditing && isDragging && isDragOver
    }"
    :draggable="isEditing && !activeEdit"
    @click="onCellClick"
    @dragstart="!activeEdit && $emit('dragstart', $event)"
    @dragend="$emit('dragend', $event)"
    @dragover.prevent="$emit('dragover', $event)"
    @dragleave="$emit('dragleave', $event)"
    @drop="$emit('drop', $event)"
  >
    <!-- delete button -->
    <BaseButton
      v-if="isEditing && session"
      variant="ghost"
      class="absolute right-1.5 top-3 hidden! group-hover:flex! h-4 w-4 p-0! items-center justify-center rounded-full bg-secondary! text-white! hover:bg-secondary/80! focus-within:flex! focus:outline-none focus:ring-1 focus:ring-secondary/20 z-20 shadow-md cursor-pointer transition-all"
      :title="t('schedule.deleteSession')"
      @click.stop="$emit('delete-session', { timeslot, room })"
    >
      <CrossIcon class="h-3 w-3" />
    </BaseButton>

    <!-- audience_interest toggle -->
    <div
      v-if="isEditing && session"
      class="absolute right-1.5 bottom-5.5 gap-1"
      :class="{
        'hidden! group-hover:flex! ': !showAdvancedControls,
        'flex!': showAdvancedControls
      }"
    >
      <ErrorIcon
        v-if="capacityMismatch === 'insufficient'"
        class="size-4 text-red-500"
      />
      <ErrorIcon
        v-if="capacityMismatch === 'excessive'"
        class="size-4 text-yellow-500"
      />

      <BaseButton
        variant="ghost"
        class="size-4 p-0! items-center justify-center rounded bg-primary/10 text-primary/70 hover:bg-primary/5 cursor-pointer transition-all text-4xs font-black"
        :title="t('schedule.audienceInterest')"
        @click.stop="$emit('toggle-audience-interest')"
      >
        {{ session.audience_interest || 'S' }}
      </BaseButton>
    </div>

    <template v-if="session || isEditing">
      <!-- Top Row: Topic (left), format (viewing), Time (right) -->
      <div
        class="flex items-start content-start gap-2 min-h-6"
        :class="{
          'pr-7': isEditing && session,
          'opacity-50': isDimmed || (isEditing && !session)
        }"
      >
        <div
          class="relative max-w-full overflow-hidden"
          :class="{
            'overflow-hidden': !isEditing,
            'overflow-visible': isEditing
          }"
        >
          <div
            class="rounded px-2 py-0.5 text-3xs font-bold uppercase tracking-wider outline-none transition-all truncate"
            :class="[
              `topic-${topicIndex}`,
              isEditing ? 'cursor-pointer focus:text-base focus:z-50' : ''
                         ]"
            :data-edit-id="`session-${id}-topic`"
            @click="
              isEditing &&
              $emit(
                'start-editing',
                'session',
                id,
                'topic',
                $event
              )
            "
            @focus="$emit('focus', $event)"
            @keydown.tab.prevent="
              $emit(
                'navigate',
                $event,
                'session',
                id,
                'topic'
              )
            "
            @drop.prevent
          >
            {{ session?.topic || (isEditing ? defaultValues.topic : '') }}
          </div>

          <!-- Topic Selection Dropdown -->
          <div
            v-if="isEditing && isActiveEdit('topic')"
            class="fixed inset-0 z-40 bg-transparent"
            @click="
              $emit(
                'stop-editing',
                'session',
                id,
                'topic'
              )
            "
          ></div>
          <div
            v-if="isEditing && isActiveEdit('topic')"
            class="topic-dropdown absolute top-full left-0 mt-1 min-w-30 max-w-50 max-h-30 overflow-y-auto bg-surface rounded-lg shadow-xl border border-gray-light/30 z-60 py-1"
            @click.stop
          >
            <div
              v-for="topic in topics"
              :key="topic.name"
              class="px-3 py-2 text-3xs font-bold uppercase truncate tracking-wider cursor-pointer hover:bg-surface-raised transition-colors flex items-center gap-2"
              :class="{ 'bg-surface-raised': session?.topic === topic.name }"
              @click="
                $emit('update-session', timeslot, room, 'topic', {
                  target: { value: topic.name }
                });
                $emit(
                  'stop-editing',
                  'session',
                  id,
                  'topic'
                )
              "
            >
              <span
                class="w-2 h-2 rounded-full"
                :class="[`topic-${topic.colorIndex}`]"
              ></span>
              {{ topic.name }}
            </div>
          </div>
        </div>

        <!-- spacer -->
        <div class="grow"></div>

        <div
          v-if="!isEditing"
          class="text-3xs font-black text-primary/30 whitespace-nowrap md:hidden py-0.5"
        >
          {{ timeslot }}
        </div>

        <!-- Documentation count badge -->
        <div
          v-if="!isEditing && !readOnly && docCount > 0"
          class="min-w-4 h-4 px-1 rounded-full text-primary bg-tertiary/50 text-4xs font-black border border-primary/10 shadow-sm"
          :title="docCount + ' ' + t('documentation.contributions')"
        >
          {{ docCount }}
        </div>
      </div>

      <!-- Middle: Title -->
      <div
        class="grow flex flex-col justify-start"
        :class="{ 'opacity-50': isDimmed && !isActiveEdit('title') }"
      >
        <span
          class="rounded-md border border-transparent font-black leading-tight text-primary text-lg md:text-xl tracking-tight transition-all"
          :class="{
            'p-1 cursor-pointer hover:bg-surface-raised focus:cursor-auto focus:border-primary focus:bg-surface focus:outline-none focus:z-50':
              isEditing,
            'text-primary/20! font-medium': isEditing && !session,
            'line-clamp-4': !isEditing || !isActiveEdit('title')
          }"
          :contenteditable="
            isEditing && isActiveEdit('title') && !isDraggingSession
          "
          :data-edit-id="`session-${id}-title`"
          @click="
            isEditing &&
            $emit(
              'start-editing',
              'session',
              id,
              'title',
              $event
            )
          "
          @focus="$emit('focus', $event)"
          @keydown.enter.prevent="$event.target.blur()"
          @keydown.tab.prevent="
            $emit(
              'navigate',
              $event,
              'session',
              id,
              'title'
            )
          "
          @blur="handleBlur($event, 'title')"
          @drop.prevent
        >
          <a
            v-if="!isEditing && session?.link"
            :href="session.link"
            class="text-secondary hover:underline transition-all underline-offset-4 decoration-2"
            @click.stop
          >
            {{ session.title }}
          </a>
          <template v-else>
            {{ session?.title || (isEditing ? defaultValues.title : '') }}
          </template>
        </span>
      </div>

      <!-- Bottom Row: Speakers, Format & Room (small screens) -->
      <div
        class="grid grid-cols-[auto_1fr_auto] w-full gap-2 mt-auto min-h-4"
        :class="{
          'pr-4': isEditing && showAdvancedControls && !capacityMismatch,
          'pr-8': isEditing && showAdvancedControls && !!capacityMismatch,
          'group-hover:pr-4':
            isEditing && !showAdvancedControls && !capacityMismatch,
          'group-hover:pr-8':
            isEditing && !showAdvancedControls && !!capacityMismatch,
          'opacity-50':
            isDimmed && !isActiveEdit('speakers') && !isActiveEdit('format')
        }"
      >
        <span
          class="rounded-md border border-transparent text-xs font-bold text-primary/70 tracking-tight transition-all max-md:col-span-3"
          :class="{
            'p-1 cursor-pointer hover:bg-surface-raised focus:text-base focus:cursor-auto focus:border-primary focus:bg-surface focus:outline-none focus:z-50':
              isEditing,
            'text-primary/20! font-medium': isEditing && !session,
            truncate: !isEditing || !isActiveEdit('speakers')
          }"
          :contenteditable="
            isEditing && isActiveEdit('speakers') && !isDraggingSession
          "
          :data-edit-id="`session-${id}-speakers`"
          @click="
            isEditing &&
            $emit(
              'start-editing',
              'session',
              id,
              'speakers',
              $event
            )
          "
          @focus="$emit('focus', $event)"
          @keydown.enter.prevent="$event.target.blur()"
          @keydown.tab.prevent="
            $emit(
              'navigate',
              $event,
              'session',
              id,
              'speakers'
            )
          "
          @blur="handleBlur($event, 'speakers')"
          @drop.prevent
        >
          {{ session?.speakers || (isEditing ? defaultValues.speakers : '') }}
        </span>

        <!-- spacer for big screens -->
        <span class="hidden md:block"></span>

        <!-- format, two versions for editing / viewing -->
        <span
          v-if="isEditing"
          class="rounded-md border border-transparent text-xs font-bold text-primary/70 tracking-tight transition-all"
          :class="{
            'p-1 cursor-pointer hover:bg-surface-raised focus:text-base focus:cursor-auto focus:border-primary focus:bg-surface focus:outline-none focus:z-50':
              isEditing,
            'text-primary/20! font-medium': isEditing && !session,
            truncate: !isEditing || !isActiveEdit('format')
          }"
          :contenteditable="
            isEditing && isActiveEdit('format') && !isDraggingSession
          "
          :data-edit-id="`session-${id}-format`"
          @click="
            isEditing &&
            $emit(
              'start-editing',
              'session',
              id,
              'format',
              $event
            )
          "
          @focus="$emit('focus', $event)"
          @keydown.enter.prevent="$event.target.blur()"
          @keydown.tab.prevent="
            $emit(
              'navigate',
              $event,
              'session',
              id,
              'format'
            )
          "
          @blur="handleBlur($event, 'format')"
          @drop.prevent
        >
          {{ session?.format || (isEditing ? defaultValues.format : '') }}
        </span>
        <div
          v-if="!isEditing && session?.format"
          class="rounded px-2 py-0.5 text-3xs uppercase font-black text-primary/70 bg-surface-raised tracking-tight transition-all truncate"
        >
          {{ session?.format }}
        </div>

        <!-- spacer for small screens -->
        <span class="hidden max-md:block"></span>

        <span
          v-if="!isEditing"
          class="rounded px-2 py-0.5 md:hidden text-3xs uppercase font-black text-primary/70 bg-surface-raised tracking-tight whitespace-nowrap"
        >
          {{ room }}
        </span>
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import BaseButton from '../base/BaseButton.vue'
import CrossIcon from '../icons/CrossIcon.vue'
import { useScheduleStore } from '../../stores/schedule'
import ErrorIcon from '../icons/ErrorIcon.vue'

const store = useScheduleStore()

const props = defineProps({
  session: {
    type: Object,
    default: null
  },
  timeslot: {
    type: String,
    required: true
  },
  room: {
    type: String,
    required: true
  },
  roomSize: {
    type: String,
    default: 'S'
  },
  timeIndex: {
    type: Number,
    required: true
  },
  roomIndex: {
    type: Number,
    required: true
  },
  id: {
    type: String,
    required: true
  },
  isDimmed: {
    type: Boolean,
    default: false
  },
  isDraggingSession: {
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
  isDraggingRelative: {
    type: Boolean,
    default: false
  },
  docCount: {
    type: Number,
    default: 0
  },
  topics: {
    type: Array,
    default: () => []
  },
  defaultValues: {
    type: Object,
    required: true
  }
})

const isEditing = computed(() => store.isEditing)
const readOnly = computed(() => store.readOnly)
const showAdvancedControls = computed(() => store.showAdvancedControls)
const topicIndex = computed(() => {
  const topic = props.topics.find(t => t.name === props.session?.topic)
  return topic?.colorIndex ?? 0
})
const activeEdit = computed(() => store.activeEdit)

const emit = defineEmits([
  'click',
  'start-editing',
  'stop-editing',
  'update-session',
  'delete-session',
  'toggle-audience-interest',
  'focus',
  'navigate',
  'dragstart',
  'dragend',
  'dragover',
  'dragleave',
  'drop'
])

const sizeValues = { S: 1, M: 2, L: 3 }

const interestLevel = computed(() => {
  return sizeValues[props.session?.audience_interest] || 1
})

const capacityLevel = computed(() => {
  return sizeValues[props.roomSize] || 1
})

const capacityMismatch = computed(() => {
  if (!props.session) return null
  const interest = interestLevel.value
  const capacity = capacityLevel.value

  if (interest > capacity) return 'insufficient'
  if (interest < capacity) return 'excessive'
  return null
})

const isActiveEdit = (field = null) => {
  return store.isActiveEdit('session', props.id, field)
}

const onCellClick = () => {
  if (!isEditing.value && props.session) {
    emit('click', props.session)
  }
}

const handleBlur = (event, field) => {
  emit('update-session', props.timeslot, props.room, field, event)
  emit('stop-editing', 'session', props.id, field)
}
</script>
