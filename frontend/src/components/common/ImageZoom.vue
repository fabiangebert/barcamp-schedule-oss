<template>
  <div v-if="src" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/95 p-4" @click="$emit('close')">
    <img :src="src" class="max-h-full max-w-full rounded-lg shadow-2xl animate-in zoom-in-95 duration-300" />
    <button class="absolute top-6 right-6 text-white opacity-70 hover:opacity-100 transition-opacity focus:outline-none focus:ring-2 focus:ring-white/20 rounded-full p-1">
      <CrossIcon class="w-8 h-8" />
    </button>
  </div>
</template>

<script setup>
import CrossIcon from '../icons/CrossIcon.vue'

defineProps({
  src: { type: String, default: null }
})

defineEmits(['close'])
</script>
