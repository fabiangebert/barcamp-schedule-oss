<template>
  <div class="flex flex-col gap-1 w-full">
    <label v-if="label" :for="id" class="text-3xs font-black uppercase tracking-widest text-gray-mid/60 ml-1">
      {{ label }}
    </label>
    <div class="relative">
      <input
        :id="id"
        ref="inputRef"
        :type="type"
        :value="modelValue"
        :placeholder="placeholder"
        :required="required"
        :autocomplete="autocomplete"
        :disabled="disabled"
        :class="[
          'w-full rounded-md border border-gray-light/50 bg-surface-raised px-4 py-2.5 text-base outline-none transition-all focus:border-primary focus:ring-2 focus:ring-primary/10 disabled:opacity-50 disabled:cursor-not-allowed',
          error ? 'border-red-500 focus:border-red-500 focus:ring-red-500/10' : ''
        ]"
        @input="$emit('update:modelValue', $event.target.value)"
        @keyup.enter="$emit('enter')"
      />
      <div v-if="error" class="text-3xs text-red-500 mt-1 ml-1">{{ error }}</div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

defineProps({
  modelValue: { type: [String, Number], default: '' },
  label: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  type: { type: String, default: 'text' },
  id: { type: String, default: () => `input-${Math.random().toString(36).substring(2, 7)}` },
  required: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  autocomplete: { type: String, default: 'off' },
  error: { type: String, default: '' }
})

defineEmits(['update:modelValue', 'enter'])

const inputRef = ref(null)

const focus = () => {
  inputRef.value?.focus()
}

const select = () => {
  inputRef.value?.select()
}

defineExpose({ focus, select })
</script>
