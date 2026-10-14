<template>
  <div class="py-20 text-center">
    <div
      v-if="alertMessage"
      class="max-w-md mx-auto mb-6 bg-amber-600/20 border border-amber-600/40 text-amber-700 dark:text-amber-300 px-4 py-3 rounded-xl text-sm shadow-sm"
    >
      {{ alertMessage }}
    </div>
    <div
      class="max-w-md mx-auto mb-6 bg-surface-raised p-6 rounded-xl border border-gray-light/30 text-sm text-gray-mid shadow-sm flex items-center gap-4"
    >
      <QrCodeIcon class="size-16"/>
      <p>
        {{ t('auth.lookingForEvent') }}
      </p>
    </div>
    <div
      class="max-w-md mx-auto bg-surface p-8 rounded-xl shadow-card border border-gray-light/30 shadow-sm"
    >
      <h2 class="text-2xl font-bold text-primary mb-4">
        {{ t('auth.login') }}
      </h2>
      <p class="mb-8 text-sm text-gray-mid">
        {{ t('auth.pleaseLogin') }}
      </p>
      <form class="flex flex-col gap-4" @submit.prevent="handleSubmit">
        <!-- Honeypot (bot trap) -->
        <input
          v-model="internalHoneypot"
          type="text"
          name="website"
          tabindex="-1"
          autocomplete="off"
          style="position: absolute; left: -9999px; top: -9999px;"
        />
        <input
          ref="emailInput"
          v-model="internalEmail"
          type="email"
          :placeholder="t('auth.emailPlaceholder')"
          :disabled="loadingState.isLoading"
          class="w-full px-4 py-2 border border-gray-light rounded-md focus:outline-none focus:ring-2 focus:ring-primary/20 text-base disabled:opacity-50 disabled:cursor-not-allowed"
        />
        <button
          type="submit"
          :disabled="!isValidEmail || loadingState.isLoading || isCooldownActive"
          :class="[
            'w-full text-white font-bold py-2 rounded-md transition-colors flex items-center justify-center gap-2',
            isValidEmail && !loadingState.isLoading && !isCooldownActive
              ? 'bg-surface-inverse hover:bg-surface-inverse/80 cursor-pointer'
              : 'bg-gray-light text-primary/60 cursor-not-allowed'
          ]"
        >
          <LoadingIcon v-if="loadingState.isLoading" class="size-5 animate-spin" />
          {{ t('auth.sendMagicLink') }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { computed, onMounted, ref } from 'vue'
import QrCodeIcon from '../components/icons/QrCodeIcon.vue'
import LoadingIcon from '../components/icons/LoadingIcon.vue'
import { loadingState } from '../utils/loadingState'

const props = defineProps({
  email: {
    type: String,
    required: true
  },
  honeypot: {
    type: String,
    default: ''
  },
  alertMessage: {
    type: String,
    default: ''
  }
})

const emit = defineEmits(['update:email', 'update:honeypot', 'login'])

const emailInput = ref(null)
const isCooldownActive = ref(false)

onMounted(() => {
  emailInput.value?.focus()
})

const internalEmail = computed({
  get: () => props.email,
  set: (value) => emit('update:email', value)
})

const internalHoneypot = computed({
  get: () => props.honeypot,
  set: (value) => emit('update:honeypot', value)
})

const isValidEmail = computed(() => {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return props.email && emailRegex.test(props.email)
})

const handleSubmit = () => {
  if (!isValidEmail.value || loadingState.isLoading || isCooldownActive.value) return

  emit('login')

  // Clear input field and set 5 seconds cooldown
  internalEmail.value = ''
  isCooldownActive.value = true
  setTimeout(() => {
    isCooldownActive.value = false
  }, 5000)
}
</script>
