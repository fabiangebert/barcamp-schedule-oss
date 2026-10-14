<template>
  <div class="space-y-4 animate-in fade-in duration-500">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
      <div class="flex items-center gap-2 bg-surface-raised rounded-lg p-1 border border-gray-light/40 w-fit">
        <button
          class="px-3 py-1.5 text-3xs font-black uppercase tracking-widest rounded-md transition-all duration-300"
          :class="activeTab === 'docs' ? 'bg-surface shadow-sm text-primary' : 'text-gray-mid/50 hover:text-primary'"
          @click="activeTab = 'docs'"
        >
          {{ t('documentation.showDocs') }}
        </button>
        <button
          class="px-3 py-1.5 text-3xs font-black uppercase tracking-widest rounded-md transition-all duration-300"
          :class="activeTab === 'leaderboard' ? 'bg-surface shadow-sm text-primary' : 'text-gray-mid/50 hover:text-primary'"
          @click="activeTab = 'leaderboard'"
        >
          {{ t('documentation.showLeaderboard') }}
        </button>
        <button
          v-if="isAdmin || isUser"
          class="px-3 py-1.5 text-3xs font-black uppercase tracking-widest rounded-md transition-all duration-300"
          :class="activeTab === 'stats' ? 'bg-surface shadow-sm text-primary' : 'text-gray-mid/50 hover:text-primary'"
          @click="activeTab = 'stats'"
        >
          {{ t('feedback.statistics') }}
        </button>
      </div>

      <div class="flex items-center gap-2">
        <BaseButton
          v-if="isAdmin || isUser"
          variant="primary-outline"
          size="dense"
          class="flex items-center gap-1.5"
          @click="$emit('export')"
        >
          <DownloadIcon class="w-3.5 h-3.5" />
          <span>
            {{ t('documentation.exportForLLM') }}
          </span>
        </BaseButton>
        <BaseButton
          v-if="(isAdmin || isUser) && activeTab === 'stats' && hasFeedbackQuestions"
          variant="primary-outline"
          size="dense"
          class="flex items-center gap-1.5"
          @click="$emit('export-feedback-csv')"
        >
          <DownloadIcon class="w-3.5 h-3.5" />
          <span>
            {{ t('feedback.exportFeedbackCsv') }}
          </span>
        </BaseButton>
        <BaseButton
          variant="primary-outline"
          size="dense"
          @click="$emit('toggle-docs')"
        >
          {{ t('schedule.schedule') }}
        </BaseButton>
      </div>
    </div>

    <h2
      v-if="activeTab === 'docs'"
      class="text-3xs font-black uppercase tracking-[0.2em] text-gray-mid/50 mb-2"
    >
      {{ t('documentation.overview') }}
    </h2>

    <div
      v-if="loading"
      class="flex flex-col items-center justify-center py-12 space-y-3"
    >
      <div
        class="w-8 h-8 border-4 border-primary border-t-transparent rounded-full animate-spin"
      ></div>
      <p
        class="text-3xs font-bold text-primary uppercase tracking-widest animate-pulse"
      >
        {{ t('documentation.loading') }}
      </p>
    </div>

    <template v-else-if="activeTab === 'docs'">
      <div
        v-if="docsWithoutFeedback.length === 0"
        class="flex flex-col items-center justify-center py-12 text-center space-y-3 opacity-30"
      >
        <DocumentEmptyIcon class="w-12 h-12" />
        <h3 class="text-sm font-black uppercase tracking-tight">
          {{ t('documentation.noDocs') }}
        </h3>
      </div>
      <DocumentationItems
        v-else
        :docs="docsWithoutFeedback"
        :schedule="schedule"
        @open-session="$emit('open-session', $event)"
        @zoom="zoomedImage = $event"
      />
    </template>

    <DocumentationLeaderboard v-else-if="activeTab === 'leaderboard'" :docs="docs" />

    <!-- Feedback Statistics Tab -->
    <template v-else-if="activeTab === 'stats'">
      <div
        v-if="feedbackStats.length === 0"
        class="flex flex-col items-center justify-center py-12 text-center space-y-3 opacity-30"
      >
        <DocumentEmptyIcon class="w-12 h-12" />
        <p class="text-3xs font-bold uppercase tracking-widest">
          {{ t('feedback.noQuestionsConfigured') }}
        </p>
      </div>

      <div v-else class="space-y-6">
        <div
          v-for="q in feedbackStats"
          :key="q.id"
          class="border border-gray-light/40 rounded-xl p-4 bg-surface space-y-3"
        >
          <div>
            <h3 class="text-sm font-black text-primary">{{ q.question }}</h3>
            <span class="text-4xs font-bold uppercase tracking-widest text-gray-mid/50">
              {{ q.total }} {{ t('feedback.responses') }}
            </span>
          </div>

          <!-- Multiple Choice or Rating: Bar chart -->
          <div v-if="q.type === 'multiple_choice' || q.type === 'rating'" class="space-y-2">
            <div v-if="q.type === 'rating' && q.avg" class="text-sm font-black text-primary">
              Ø {{ q.avg }} / 10
            </div>
            <div v-for="r in q.results" :key="r.label" class="space-y-0.5">
              <div class="flex justify-between text-3xs font-bold">
                <span>{{ r.label }}</span>
                <span class="text-gray-mid">{{ r.count }}× ({{ r.percent }}%)</span>
              </div>
              <div class="h-2 bg-gray-light/30 rounded-full overflow-hidden">
                <div
                  class="h-full bg-surface-inverse rounded-full transition-all duration-500"
                  :style="{ width: r.percent + '%' }"
                />
              </div>
            </div>
          </div>

          <!-- Free text: List of answers -->
          <div v-else class="space-y-1.5">
            <div
              v-for="(answer, idx) in q.results"
              :key="idx"
              class="text-sm bg-surface-sunken border border-gray-light/30 rounded-md px-3 py-2"
            >
              {{ answer }}
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- Image Modal (Zoom) -->
    <ImageZoom :src="zoomedImage" @close="zoomedImage = null" />
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import DownloadIcon from '../icons/DownloadIcon.vue'
import DocumentEmptyIcon from '../icons/DocumentEmptyIcon.vue'
import DocumentationItems from './DocumentationItems.vue'
import DocumentationLeaderboard from './DocumentationLeaderboard.vue'
import ImageZoom from '../common/ImageZoom.vue'
import axios from 'axios'
import { ref, computed, onMounted, watch } from 'vue'
import { API_BASE } from '../../constants'
import BaseButton from '../base/BaseButton.vue'

const props = defineProps({
  schedule: { type: Object, required: true },
  documentationCounts: { type: Object, default: null },
  isAdmin: { type: Boolean, default: false },
  isUser: { type: Boolean, default: false }
})

defineEmits(['export', 'export-feedback-csv', 'open-session', 'toggle-docs'])

const docs = ref([])
const loading = ref(true)
const zoomedImage = ref(null)
const activeTab = ref('docs')

const isFeedbackDoc = (d) => {
  try {
    const p = JSON.parse(d.content)
    return p && typeof p === 'object' && 'feedback_answers' in p
  } catch {
    return false
  }
}

const docsWithoutFeedback = computed(() => docs.value.filter(d => !isFeedbackDoc(d)))

const feedbackQuestions = computed(() => props.schedule.data?.feedback_questions ?? [])

// Without configured questions there is nothing the CSV export can ever contain,
// so the button is hidden rather than offering a download that is always empty.
const hasFeedbackQuestions = computed(() => feedbackQuestions.value.length > 0)

const feedbackStats = computed(() => {
  const questions = feedbackQuestions.value
  if (!questions.length) return []

  // Answers from feedback_answers field
  const answerDocs = docs.value.filter(isFeedbackDoc)

  return questions.map(q => {
    const allAnswers = answerDocs
      .map(d => {
        try {
          const p = JSON.parse(d.content)
          return p.feedback_answers[q.id]
        } catch { return undefined }
      })
      .filter(a => a !== null && a !== undefined && a !== '')

    if (q.type === 'multiple_choice') {
      const counts = {}
      q.options.forEach(opt => { counts[opt] = 0 })
      allAnswers.forEach(a => { if (counts[a] !== undefined) counts[a]++ })
      const total = allAnswers.length
      return {
        ...q,
        total,
        results: q.options.map(opt => ({
          label: opt,
          count: counts[opt],
          percent: total > 0 ? Math.round((counts[opt] / total) * 100) : 0
        }))
      }
    } else if (q.type === 'rating') {
      const nums = allAnswers.filter(a => typeof a === 'number')
      const avg = nums.length > 0 ? (nums.reduce((s, n) => s + n, 0) / nums.length).toFixed(1) : null
      const counts = {}
      for (let i = 1; i <= 10; i++) counts[i] = 0
      nums.forEach(n => { if (counts[n] !== undefined) counts[n]++ })
      return {
        ...q,
        total: nums.length,
        avg,
        results: Object.entries(counts).map(([label, count]) => ({
          label,
          count,
          percent: nums.length > 0 ? Math.round((count / nums.length) * 100) : 0
        }))
      }
    } else {
      return { ...q, total: allAnswers.length, results: allAnswers }
    }
  })
})

const fetchAllDocs = async () => {
  try {
    const response = await axios.get(
      `${API_BASE}/schedules/${props.schedule.hashed_id}/documentation`,
      {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('token')}`
        }
      }
    )
    docs.value = response.data.data || []
  } catch (e) {
    console.error('Failed to fetch all documentation', e)
  } finally {
    loading.value = false
  }
}

onMounted(fetchAllDocs)

watch(
  () => props.documentationCounts,
  () => {
    fetchAllDocs()
  }
)
</script>
