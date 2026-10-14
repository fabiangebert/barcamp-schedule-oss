<template>
  <div class="group">
    <div class="flex items-center justify-between gap-2">
      <div 
        class="flex items-center gap-1.5 px-1.5 py-0.5 rounded-md"
        :style="{ backgroundColor: userColor + '15' }"
      >
        <span 
          class="text-4xs font-black uppercase tracking-widest"
          :style="{ color: userColor }"
        >
          {{ isOwn ? t('common.me') : (doc.display_name || t('common.anonymous')) }}
        </span>
        <div 
          class="flex items-center" 
          :title="doc.is_public ? t('documentation.visibility.user') : t('documentation.visibility.guest')"
        >
          <EyeIcon v-if="doc.is_public" class="w-2.5 h-2.5 opacity-40 ml-0.5" :style="{ color: userColor }" />
          <EyeOffIcon v-else class="w-2.5 h-2.5 opacity-60 ml-0.5" :style="{ color: userColor }" />
        </div>
      </div>
      <div class="flex items-center justify-between gap-2">
        <span v-if="doc.rating" class="text-4xs font-black uppercase tracking-widest text-secondary bg-secondary/10 px-1.5 py-0.5 rounded-md">
          {{ doc.rating }}/10
        </span>
        <span class="text-5xs font-bold text-gray-mid opacity-40">{{ formatDate(doc.created_at) }}</span>
        <button
          v-if="canDelete"
          class="text-gray-mid hover:text-secondary opacity-0 group-hover:opacity-100 p-0.5 focus:outline-none focus:ring-1 focus:ring-secondary/20 rounded transition-opacity"
          :title="t('documentation.deleteContribution')"
          @click.stop="$emit('delete', doc.id)"
        >
          <TrashIcon class="w-2.5 h-2.5" />
        </button>
      </div>
    </div>
    <div
      :class="[
        'text-xs text-primary leading-tight overflow-hidden transition-all duration-300',
        variant === 'bubble' ? 'rounded-md bg-surface-raised p-3 shadow-sm border border-gray-light/20' : '',
        !expanded && isLongContent ? 'max-h-24 relative' : ''
      ]"
      :style="variant === 'bubble' ? { borderLeftColor: userColor, borderLeftWidth: '3px' } : {}"
    >
      <p v-if="doc.content" class="whitespace-pre-wrap">{{ doc.content }}</p>
      
      <!-- Gradient overlay for collapsed long text -->
      <div 
        v-if="!expanded && isLongContent" 
        class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-white via-white/80 to-transparent pointer-events-none"
        :class="variant === 'bubble' ? 'from-gray-bright via-gray-bright/80' : ''"
      ></div>

      <div
        v-if="(doc.images && doc.images.length > 0) || doc.image_path"
        class="mt-2 flex gap-2 overflow-x-auto pb-2 scrollbar-hide snap-x"
      >
        <template v-for="(img, idx) in allImages" :key="idx">
          <div v-if="!img.toLowerCase().endsWith('.pdf')" class="flex-none w-20 h-20 group relative overflow-hidden rounded-md border border-gray-light/20 shadow-sm snap-start">
            <img 
              :src="img" 
              class="h-full w-full object-cover transition-transform duration-500 hover:scale-110 cursor-zoom-in" 
              @click.stop="$emit('zoom', img)" 
            />
          </div>
          <a
            v-else
            :href="img"
            target="_blank"
            class="flex-none w-20 h-20 flex flex-col items-center justify-center rounded-md bg-surface border border-gray-light/20 p-1 shadow-sm hover:shadow-md transition-all duration-300 group/pdf snap-start"
            @click.stop
          >
            <DocumentIcon class="w-6 h-6 text-primary/40 group-hover/pdf:text-primary transition-colors" />
            <span class="mt-1 text-6xs font-black uppercase tracking-tighter text-gray-mid truncate w-full text-center px-1">{{ img.split('/').pop() }}</span>
          </a>
        </template>
      </div>
    </div>
    
    <button 
      v-if="isLongContent" 
      class="mt-1 text-4xs font-black uppercase tracking-widest text-primary/40 hover:text-primary transition-colors"
      @click.stop="expanded = !expanded"
    >
      {{ expanded ? t('common.showLess') : t('common.showMore') }}
    </button>
  </div>
</template>

<script setup>
import i18n from '../../i18n'
import { parseStoredDate } from '../../utils/datetime.js'
import TrashIcon from '../icons/TrashIcon.vue'
import DocumentIcon from '../icons/DocumentIcon.vue'
import EyeIcon from '../icons/EyeIcon.vue'
import EyeOffIcon from '../icons/EyeOffIcon.vue'
import { useI18n } from 'vue-i18n'
import { ref, computed } from 'vue'
import { useUserColor } from '../../composables/useUserColor'
import { useDisplayMode } from '../../composables/useDisplayMode'
import { API_BASE } from '../../constants'
const { t } = useI18n()
const { isDark } = useDisplayMode()

const props = defineProps({
  doc: { type: Object, required: true },
  canDelete: { type: Boolean, default: false },
  variant: { type: String, default: 'default' }, // 'default' or 'bubble'
  isOwn: { type: Boolean, default: false }
})

const { getColor } = useUserColor()

const userColor = computed(() =>
  getColor(props.doc.guest_id || props.doc.user_id || props.doc.display_name, { dark: isDark.value })
)

defineEmits(['delete', 'zoom'])

const expanded = ref(false)

const allImages = computed(() => {
  let images = []
  if (props.doc.images && props.doc.images.length > 0) {
    images = props.doc.images
  } else if (props.doc.image_path) {
    images = [props.doc.image_path]
  }
  return images.map(img => {
    if (typeof img === 'string' && img.startsWith('/api/')) {
      return img.replace(/^\/api/, API_BASE)
    }
    return img
  })
})

const isLongContent = computed(() => {
  const hasManyImages = allImages.value.length > 2
  const hasLongText = props.doc.content && props.doc.content.length > 200
  return hasManyImages || hasLongText
})

const formatDate = (dateStr) => {
  const date = parseStoredDate(dateStr)
  if (!date) return ''
  return date.toLocaleString(i18n.global.locale.value, {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  })
}</script>
