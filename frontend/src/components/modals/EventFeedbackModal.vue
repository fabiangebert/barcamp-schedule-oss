<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-2 backdrop-blur-sm"
    @click.self="$emit('close')"
  >
    <div
      class="flex w-full max-w-xl flex-col rounded-xl bg-surface shadow-2xl overflow-hidden border border-gray-light/40"
    >
      <!-- Header -->
      <div
        class="flex items-center justify-between border-b border-gray-light/40 px-4 py-2 bg-surface-sunken"
      >
        <div>
          <h2 class="text-sm font-black tracking-tight text-primary uppercase">
            {{ t('documentation.eventFeedback') }}
          </h2>
          <div
            class="text-3xs font-black uppercase tracking-widest text-gray-mid/50 mt-0.5"
          >
            {{ scheduleName }}
          </div>
        </div>
        <button
          class="rounded-md p-1 text-gray-mid hover:bg-gray-light/20 hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
          @click="$emit('close')"
        >
          <CrossIcon class="w-[18px] h-[18px]" />
        </button>
      </div>

      <!-- Content (Form Only) -->
      <div
        ref="scrollContainer"
        class="overflow-y-auto p-4 space-y-4 bg-surface"
      >
        <div
          v-if="loading"
          class="flex flex-col items-center justify-center py-8 space-y-2"
        >
          <div
            class="w-6 h-6 border-3 border-primary border-t-transparent rounded-full animate-spin"
          ></div>
          <p
            class="text-3xs font-bold uppercase tracking-widest text-gray-mid animate-pulse"
          >
            {{ t('common.loading') }}
          </p>
        </div>

        <div
          v-else-if="submitted"
          class="flex flex-col items-center justify-center py-12 px-6 text-center space-y-4 animate-in fade-in zoom-in duration-300"
        >
          <div class="rounded-full bg-success/10 p-4 text-success">
            <CheckIcon class="w-12 h-12" />
          </div>
          <div class="space-y-1">
            <h3 class="text-lg font-black text-primary uppercase tracking-tight">
              {{ t('feedback.thankYou') }}
            </h3>
            <p class="text-xs font-bold text-gray-mid uppercase tracking-widest">
              {{ t('feedback.thankYouMessage') }}
            </p>
          </div>
          <button
            class="mt-4 px-6 py-2 bg-surface-inverse text-white text-3xs font-black uppercase tracking-widest rounded-md hover:bg-surface-inverse/90 transition-all shadow-md"
            @click="$emit('close')"
          >
            {{ t('common.close') }}
          </button>
        </div>

        <form v-else class="space-y-4" @submit.prevent="handleSubmit">
          <!-- Dynamic Feedback Questions -->
          <div v-if="feedbackQuestions.length > 0" class="space-y-4">
            <div v-for="q in feedbackQuestions" :key="q.id" class="flex flex-col space-y-1.5">
              <label class="text-4xs font-black uppercase tracking-widest text-gray-mid opacity-70 ml-1">
                {{ q.question }}
              </label>

              <!-- Multiple Choice -->
              <div v-if="q.type === 'multiple_choice'" class="flex flex-wrap gap-1.5">
                <button
                  v-for="opt in q.options"
                  :key="opt"
                  type="button"
                  :class="['px-3 py-1.5 rounded-md text-xs font-bold border transition-all',
                    questionAnswers[q.id] === opt
                      ? 'bg-surface-inverse text-white border-surface-inverse shadow-md'
                      : 'bg-surface text-primary border-gray-light/50 hover:border-primary/50']"
                  @click="questionAnswers[q.id] = opt"
                >
                  {{ opt }}
                </button>
              </div>

              <!-- Rating (1–10) -->
              <div v-else-if="q.type === 'rating'" class="space-y-1">
                <div class="flex items-center gap-1">
                  <button
                    v-for="n in 10"
                    :key="n"
                    type="button"
                    :class="[
                      'aspect-square max-w-8 flex-1 min-w-0 rounded-md text-3xs font-black transition-all border',
                      questionAnswers[q.id] === n
                        ? 'bg-surface-inverse text-white border-surface-inverse shadow-md scale-110'
                        : 'bg-surface text-primary border-gray-light/50 hover:border-primary/50'
                    ]"
                    @click="questionAnswers[q.id] = n"
                  >
                    {{ n }}
                  </button>
                </div>
                <div class="flex justify-between px-1 mt-0.5">
                  <span class="text-5xs text-gray-mid font-medium italic">1 = {{ t('documentation.notLikely') }}</span>
                  <span class="text-5xs text-gray-mid font-medium italic text-right">10 = {{ t('documentation.extremelyLikely') }}</span>
                </div>
              </div>

              <!-- Free Text -->
              <textarea
                v-else
                v-model="questionAnswers[q.id]"
                class="w-full rounded-md border border-gray-light/50 bg-surface p-2 text-sm outline-none focus:border-primary resize-none min-h-12"
                :disabled="submitting"
              />
            </div>
          </div>

          <div v-else class="text-center py-8 opacity-40">
            <p class="text-3xs font-bold uppercase tracking-widest">
              {{ t('feedback.noQuestionsConfigured') }}
            </p>
          </div>

          <!-- Visibility Note -->
          <p class="text-3xs text-gray-mid/60 font-medium italic mt-2">
            {{ t("documentation.guestVisibilityNote") }}
          </p>

          <!-- Submit Button -->
          <div class="flex justify-end pt-2">
            <button
              type="submit"
              class="flex items-center justify-center gap-1.5 rounded-md border border-surface-inverse bg-surface-inverse px-6 py-2.5 text-3xs font-black uppercase tracking-widest text-white hover:bg-surface-inverse/90 focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:opacity-50 transition-all shadow-md"
              :disabled="submitting || !hasQuestionAnswers"
            >
              <span
                v-if="submitting"
                class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"
              ></span>
              <span v-else>{{ t('feedback.submit') }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref, computed, watch, onMounted } from 'vue'
import CrossIcon from '../icons/CrossIcon.vue'
import CheckIcon from '../icons/CheckIcon.vue'
import { useDocumentationModal } from '../../composables/useDocumentationModal'

const props = defineProps({
  scheduleId: { type: String, required: true },
  scheduleName: { type: String, default: '' },
  userName: { type: String, required: true },
  isGuest: { type: Boolean, default: false },
  guestId: { type: String, default: '' },
  userId: { type: [Number, String], default: null },
  token: { type: String, default: '' },
  documentationCounts: { type: Object, default: null },
  feedbackQuestions: { type: Array, default: () => [] }
})

const emit = defineEmits(['close', 'notify'])

const questionAnswers = ref({})
const submitted = ref(false)

const initAnswers = () => {
  questionAnswers.value = Object.fromEntries(
    props.feedbackQuestions.map(q => [
      q.id,
      q.type === 'multiple_choice' || q.type === 'rating' ? null : ''
    ])
  )
}

watch(() => props.feedbackQuestions, initAnswers, { immediate: true })

const hasQuestionAnswers = computed(() =>
  Object.values(questionAnswers.value).some(v => v !== null && v !== '')
)

const {
  loading,
  submitting,
  scrollContainer,
  fetchDocs,
  submitDoc
} = useDocumentationModal(props, emit, () => ({ timeslot: 'event', room: 'event' }), t)

const handleSubmit = async () => {
  if (!hasQuestionAnswers.value) return

  // Find any rating type question and pass its value to the top-level 'rating' field
  const ratingQuestion = props.feedbackQuestions.find(q => q.type === 'rating')
  const ratingValue = ratingQuestion ? questionAnswers.value[ratingQuestion.id] : null

  // Save all data in a single documentation entry
  // feedback_answers are saved as a JSON string in content
  await submitDoc({
    content: JSON.stringify({ feedback_answers: questionAnswers.value }),
    rating: ratingValue
  })

  submitted.value = true
  initAnswers()
}

onMounted(fetchDocs)
</script>
