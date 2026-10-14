<template>
  <div v-if="visible" :style="zIndexStyle" :class="['fixed inset-0 flex items-center justify-center p-4']">
    <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="handleBackdropClick"></div>
    <div 
      :class="[
        'relative z-10 w-full rounded-xl bg-surface shadow-2xl border border-gray-light/50 overflow-hidden transition-all transform',
        maxWidthClass
      ]"
    >
      <slot name="header">
        <div v-if="title" class="p-4 border-b border-gray-light/50 flex justify-between items-center bg-surface-sunken">
          <h3 class="text-lg font-black text-primary uppercase tracking-tight">{{ title }}</h3>
          <button v-if="closable" class="text-gray-mid hover:text-primary p-1 rounded-md hover:bg-gray-light/20 transition-all" @click="handleCloseClick">
            <CrossIcon class="h-6 w-6" />
          </button>
        </div>
      </slot>
      
      <div class="p-6">
        <slot></slot>
      </div>

      <slot name="footer"></slot>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import CrossIcon from '../icons/CrossIcon.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: '' },
  maxWidth: { type: String, default: 'sm' }, // 'sm', 'md', 'lg', 'xl', '2xl'
  zIndex: { type: Number, default: 100 },
  closable: { type: Boolean, default: true }
})

const emit = defineEmits(['close'])

const handleBackdropClick = () => {
  if (props.closable) {
    emit('close')
  }
}

const handleCloseClick = () => {
  if (props.closable) {
    emit('close')
  }
}

const zIndexStyle = computed(() => ({ zIndex: props.zIndex }))

const maxWidthClass = computed(() => {
  const classes = {
    'sm': 'max-w-sm',
    'md': 'max-w-md',
    'lg': 'max-w-lg',
    'xl': 'max-w-xl',
    '2xl': 'max-w-2xl'
  }
  return classes[props.maxWidth] || classes['sm']
})
</script>
