<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :class="[
      'inline-flex items-center justify-center rounded-md text-3xs font-black uppercase tracking-widest transition-all focus:outline-none focus:ring-2 focus:ring-offset-1 disabled:opacity-50 disabled:cursor-not-allowed active:transform active:scale-95',
      variantClasses,
      sizeClasses
    ]"
    @click="$emit('click', $event)"
  >
    <LoadingIcon
      v-if="loading"
      class="animate-spin -ml-1 mr-2 h-4 w-4 text-current"
    />
    <slot></slot>
  </button>
</template>

<script setup>
import { computed } from 'vue'
import LoadingIcon from '../icons/LoadingIcon.vue'

const props = defineProps({
  variant: {
    type: String,
    default: 'primary',
    validator: (v) =>
      ['primary', 'secondary', 'ghost', 'primary-outline'].includes(v)
  },
  size: {
    type: String,
    default: 'md',
    validator: (v) => ['md', 'dense', 'sm'].includes(v)
  },
  type: { type: String, default: 'button' },
  disabled: { type: Boolean, default: false },
  loading: { type: Boolean, default: false }
})

defineEmits(['click'])

const VARIANTS = {
  primary:
    'bg-surface-inverse text-white border border-surface-inverse hover:bg-surface-inverse/90 focus:ring-primary/20 shadow-sm',
  secondary:
    'bg-secondary text-white border border-secondary hover:bg-secondary/90 focus:ring-secondary/20 shadow-sm',
  ghost:
    'bg-surface-sunken text-gray-mid border border-gray-light/50 hover:bg-surface-raised focus:ring-gray-light/20',
  'primary-outline':
    'bg-surface text-primary border border-primary/30 hover:bg-primary/5 focus:ring-primary/20 shadow-sm'
}

const SIZES = {
  md: 'px-4 py-2',
  dense: 'px-2 py-1',
  sm: 'px-3 py-1 text-4xs'
}

const variantClasses = computed(
  () => VARIANTS[props.variant] || VARIANTS.primary
)
const sizeClasses = computed(() => SIZES[props.size] || SIZES.md)
</script>
