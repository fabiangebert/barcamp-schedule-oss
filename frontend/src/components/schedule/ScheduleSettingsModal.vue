<template>
  <BaseModal
    :visible="visible"
    :title="t('modals.scheduleSettingsTitle')"
    max-width="md"
    @close="$emit('close')"
  >
    <div class="space-y-4 pt-2">
      <BaseInput
        ref="nameInput"
        v-model="internalName"
        :label="t('common.name')"
        @enter="handleConfirm"
      />
      <BaseInput
        v-model="internalDate"
        type="date"
        :label="t('common.date')"
        @enter="handleConfirm"
      />

      <!-- Feedback Questions -->
      <div class="space-y-3 pt-2 border-t border-gray-light/20">
        <div class="flex items-center justify-between">
          <label class="text-4xs font-black uppercase tracking-widest text-gray-mid opacity-70">
            {{ t('feedback.questions') }}
          </label>
          <BaseButton size="dense" variant="ghost" @click="addQuestion">
            + {{ t('feedback.addQuestion') }}
          </BaseButton>
        </div>

        <div class="overflow-y-auto max-h-75 flex flex-col gap-3">
          <div
          v-for="(q, qIdx) in internalQuestions"
          :key="q.id"
          class="border border-gray-light/40 rounded-lg p-3 space-y-2 bg-surface-sunken"
        >
          <!-- Question Text -->
          <BaseInput v-model="q.question" :label="t('feedback.questionLabel', { n: qIdx + 1 })" />

          <!-- Type Selection -->
          <div class="flex gap-2 flex-wrap">
            <button
              type="button"
              :class="['text-4xs font-black uppercase tracking-widest px-2 py-1 rounded border transition-all',
                q.type === 'multiple_choice' ? 'bg-surface-inverse text-white border-surface-inverse' : 'border-gray-light/50 text-gray-mid']"
              @click="q.type = 'multiple_choice'; delete q.options || (q.options = ['', ''])"
            >
              {{ t('feedback.multipleChoice') }}
            </button>
            <button
              type="button"
              :class="['text-4xs font-black uppercase tracking-widest px-2 py-1 rounded border transition-all',
                q.type === 'text' ? 'bg-surface-inverse text-white border-surface-inverse' : 'border-gray-light/50 text-gray-mid']"
              @click="q.type = 'text'"
            >
              {{ t('feedback.textAnswer') }}
            </button>
            <button
              type="button"
              :class="['text-4xs font-black uppercase tracking-widest px-2 py-1 rounded border transition-all',
                q.type === 'rating' ? 'bg-surface-inverse text-white border-surface-inverse' : 'border-gray-light/50 text-gray-mid']"
              @click="q.type = 'rating'"
            >
              {{ t('feedback.rating') }}
            </button>
          </div>

          <!-- Answer Options (multiple_choice only) -->
          <div v-if="q.type === 'multiple_choice'" class="space-y-1.5">
            <div v-for="(opt, oIdx) in q.options" :key="oIdx" class="flex gap-1.5 items-center">
              <input
                v-model="q.options[oIdx]"
                :placeholder="`${t('feedback.option')} ${oIdx + 1}`"
                class="flex-1 text-sm border border-gray-light/50 rounded px-2 py-1 outline-none focus:border-primary"
              />
              <button type="button" class="text-gray-mid hover:text-secondary" @click="removeOption(qIdx, oIdx)">✕</button>
            </div>
            <button
              type="button"
              class="text-4xs font-black uppercase tracking-widest text-primary hover:underline"
              @click="addOption(qIdx)"
            >
              + {{ t('feedback.addOption') }}
            </button>
          </div>

          <!-- Move or Delete Question -->
          <div class="flex justify-between items-center">
            <div class="flex gap-1.5">
              <button
                type="button"
                class="text-4xs font-black uppercase tracking-widest text-gray-mid hover:text-primary disabled:opacity-30"
                :disabled="qIdx === 0"
                @click="moveQuestion(qIdx, -1)"
              >
                ↑ {{ t('feedback.moveUp') }}
              </button>
              <button
                type="button"
                class="text-4xs font-black uppercase tracking-widest text-gray-mid hover:text-primary disabled:opacity-30"
                :disabled="qIdx === internalQuestions.length - 1"
                @click="moveQuestion(qIdx, 1)"
              >
                ↓ {{ t('feedback.moveDown') }}
              </button>
            </div>
            <button
              type="button"
              class="text-4xs font-black uppercase tracking-widest text-secondary hover:underline"
              @click="removeQuestion(qIdx)"
            >
              {{ t('feedback.removeQuestion') }}
            </button>
          </div>
        </div>
        </div>
      </div>

      <div class="flex justify-end gap-3 pt-4 border-t border-gray-light/20">
        <BaseButton variant="ghost" @click="$emit('close')">
          {{ t('common.cancel') }}
        </BaseButton>
        <BaseButton @click="handleConfirm">
          {{ t('common.save') }}
        </BaseButton>
      </div>
    </div>
  </BaseModal>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref, watch, nextTick } from 'vue'
import BaseModal from '../base/BaseModal.vue'
import BaseButton from '../base/BaseButton.vue'
import BaseInput from '../base/BaseInput.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  name: { type: String, default: '' },
  date: { type: String, default: '' },
  feedbackQuestions: { type: Array, default: () => [] }
})

const emit = defineEmits(['close', 'confirm'])

const nameInput = ref(null)
const internalName = ref(props.name)
const internalDate = ref(props.date)
const internalQuestions = ref([])

watch(() => props.visible, (newVal) => {
  if (newVal) {
    internalName.value = props.name
    internalDate.value = props.date
    internalQuestions.value = JSON.parse(JSON.stringify(props.feedbackQuestions || []))
    nextTick(() => {
      nameInput.value?.focus()
      nameInput.value?.select()
    })
  }
})

const addQuestion = () => {
  internalQuestions.value.push({
    id: `q_${Date.now()}`,
    type: 'multiple_choice',
    question: '',
    options: ['', '']
  })
}

const removeQuestion = (index) => {
  internalQuestions.value.splice(index, 1)
}

const addOption = (qIndex) => {
  internalQuestions.value[qIndex].options.push('')
}

const removeOption = (qIndex, oIndex) => {
  internalQuestions.value[qIndex].options.splice(oIndex, 1)
}

const moveQuestion = (index, direction) => {
  const newIndex = index + direction
  if (newIndex < 0 || newIndex >= internalQuestions.value.length) return
  const arr = internalQuestions.value
  const tmp = arr[index]
  arr[index] = arr[newIndex]
  arr[newIndex] = tmp
}

const handleConfirm = () => {
  emit('confirm', {
    name: internalName.value,
    date: internalDate.value,
    feedbackQuestions: internalQuestions.value
  })
}
</script>
