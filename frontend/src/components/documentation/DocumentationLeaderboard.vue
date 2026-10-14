<template>
  <div class="space-y-6 animate-in fade-in slide-in-from-bottom-2 duration-500">
    <div class="grid gap-4 md:grid-cols-2">
      <!-- Word Winner -->
      <div v-if="wordWinner" class="bg-tertiary/10 border border-tertiary/20 rounded-xl p-4 flex items-center gap-4 relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 opacity-5 group-hover:scale-110 transition-transform duration-700">
           <PencilIcon class="w-20 h-20 text-primary" />
        </div>
        <div class="bg-tertiary/20 p-3 rounded-full">
          <PencilIcon class="w-5 h-5 text-primary" />
        </div>
        <div>
          <h3 class="text-3xs font-black uppercase tracking-widest text-primary/60">
            {{ t('documentation.leaderboard.wordWinner') }}
          </h3>
          <p class="text-lg font-black text-primary leading-tight">
            {{ wordWinner.name }}
          </p>
          <p class="text-3xs font-black uppercase tracking-widest text-gray-mid/50 mt-0.5">
            {{ wordWinner.words }} {{ t('documentation.leaderboard.words') }}
          </p>
        </div>
      </div>

      <!-- Image Winner -->
      <div v-if="imageWinner" class="bg-secondary/10 border border-secondary/20 rounded-xl p-4 flex items-center gap-4 relative overflow-hidden group">
        <div class="absolute -right-4 -top-4 opacity-5 group-hover:scale-110 transition-transform duration-700">
           <CameraIcon class="w-20 h-20 text-secondary" />
        </div>
        <div class="bg-secondary/20 p-3 rounded-full">
          <CameraIcon class="w-5 h-5 text-secondary" />
        </div>
        <div>
          <h3 class="text-3xs font-black uppercase tracking-widest text-secondary/60">
            {{ t('documentation.leaderboard.imageWinner') }}
          </h3>
          <p class="text-lg font-black text-secondary leading-tight">
            {{ imageWinner.name }}
          </p>
          <p class="text-3xs font-black uppercase tracking-widest text-gray-mid/50 mt-0.5">
            {{ imageWinner.images }} {{ t('documentation.leaderboard.images') }}
          </p>
        </div>
      </div>
    </div>

    <div class="bg-surface rounded-xl border border-gray-light/40 overflow-hidden shadow-md">
      <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse">
          <thead>
            <tr class="bg-surface-sunken border-b border-gray-light/40">
              <th class="px-6 py-3 text-3xs font-black uppercase tracking-widest text-gray-mid/60">{{ t('documentation.leaderboard.rank') }}</th>
              <th class="px-6 py-3 text-3xs font-black uppercase tracking-widest text-gray-mid/60">{{ t('documentation.leaderboard.user') }}</th>
              <th class="px-6 py-3 text-3xs font-black uppercase tracking-widest text-gray-mid/60 text-right">{{ t('documentation.leaderboard.words') }}</th>
              <th class="px-6 py-3 text-3xs font-black uppercase tracking-widest text-gray-mid/60 text-right">{{ t('documentation.leaderboard.images') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-light/40">
            <tr v-for="(entry, index) in sortedLeaderboard" :key="entry.id" class="hover:bg-surface-raised transition-colors">
              <td class="px-6 py-4">
                <span
                  :class="[
                  'w-6 h-6 rounded-full flex items-center justify-center text-3xs font-black',
                  index === 0 ? 'bg-yellow-400 text-white' : 
                  index === 1 ? 'bg-gray-300 text-white' :
                  index === 2 ? 'bg-orange-400 text-white' : 'text-gray-mid/40'
                ]">
                  {{ index + 1 }}
                </span>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-3">
                  <div 
                    class="w-2 h-2 rounded-full" 
                    :style="{ backgroundColor: getColor(entry.id || entry.name, { dark: isDark }) }"
                  ></div>
                  <span class="text-sm font-black text-primary uppercase tracking-tight">{{ entry.name }}</span>
                </div>
              </td>
              <td class="px-6 py-4 text-sm font-black text-gray-mid/70 text-right uppercase tracking-tighter">
                {{ entry.words }}
              </td>
              <td class="px-6 py-4 text-sm font-black text-gray-mid/70 text-right uppercase tracking-tighter">
                {{ entry.images }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { computed } from 'vue'
import PencilIcon from '../icons/PencilIcon.vue'
import CameraIcon from '../icons/CameraIcon.vue'
import { useUserColor } from '../../composables/useUserColor'
import { useDisplayMode } from '../../composables/useDisplayMode'

const props = defineProps({
  docs: { type: Array, required: true }
})

const { getColor } = useUserColor()
const { isDark } = useDisplayMode()

const leaderboardData = computed(() => {
  const users = {}

  props.docs.forEach(doc => {
    // Skip feedback items (marked as timeslot 'event' and room 'event')
    if (doc.timeslot === 'event' && doc.room === 'event') {
      return
    }

    const id = doc.guest_id || doc.user_id || doc.display_name
    const name = doc.display_name || 'Anonymous'

    if (!users[id]) {
      users[id] = { id, name, words: 0, images: 0, score: 0 }
    }

    // Count words
    if (doc.content) {
      const words = doc.content.trim().split(/\s+/).filter(w => w.length > 0).length
      users[id].words += words
    }

    // Count images
    if (doc.images && Array.isArray(doc.images)) {
      users[id].images += doc.images.length
    } else if (doc.image_path) {
      users[id].images += 1
    }
  })

  return Object.values(users)
})

const sortedLeaderboard = computed(() => {
  return [...leaderboardData.value].sort((a, b) => {
    const scoreA = a.words + a.images * 10
    const scoreB = b.words + b.images * 10
    return scoreB - scoreA
  })
})

const wordWinner = computed(() => {
  if (leaderboardData.value.length === 0) return null
  return [...leaderboardData.value].sort((a, b) => b.words - a.words)[0]
})

const imageWinner = computed(() => {
  if (leaderboardData.value.length === 0) return null
  return [...leaderboardData.value].sort((a, b) => b.images - a.images)[0]
})
</script>
