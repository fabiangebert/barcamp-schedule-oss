<template>
  <BaseModal
:visible="visible"
:title="title"
:closable="closable"
:z-index="2000"
@close="onClose">
    <div class="flex items-center gap-3 mb-3">
      <div v-if="type === 'success'" class="flex h-8 w-8 items-center justify-center rounded-full bg-green-600/20 text-green-700 dark:text-green-400">
        <SuccessIcon class="h-5 w-5" />
      </div>
      <div v-else-if="type === 'error'" class="flex h-8 w-8 items-center justify-center rounded-full bg-red-600/20 text-red-700 dark:text-red-400">
        <ErrorIcon class="h-5 w-5" />
      </div>
    </div>
    <p class="text-sm text-gray-mid">{{ message }}</p>

    <template #footer>
      <div class="flex justify-end gap-2 p-4 pt-0">
        <BaseButton
          variant="primary"
          @click="onConfirm"
        >
          {{ confirmText || 'OK' }}
        </BaseButton>
        <BaseButton
          v-if="closable && (type === 'info' || confirmText !== 'OK')"
          :variant="type === 'info' ? 'ghost' : 'primary'"
          @click="onClose"
        >
          {{ type === 'info' ? (cancelText || 'Cancel') : 'OK' }}
        </BaseButton>
      </div>
    </template>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../base/BaseModal.vue'
import BaseButton from '../base/BaseButton.vue'
import SuccessIcon from '../icons/SuccessIcon.vue'
import ErrorIcon from '../icons/ErrorIcon.vue'
defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: 'Notification' },
  message: { type: String, default: '' },
  type: { type: String, default: 'success' }, // 'success', 'error', 'info'
  confirmText: { type: String, default: 'OK' },
  cancelText: { type: String, default: 'Cancel' },
  closable: { type: Boolean, default: true }
})

const emit = defineEmits(['close', 'confirm'])
const onClose = () => emit('close')
const onConfirm = () => emit('confirm')
</script>
