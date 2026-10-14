<template>
  <BaseModal :visible="visible" :title="title" @close="onCancel">
    <p class="text-sm text-gray-mid">{{ message }}</p>

    <template #footer>
      <div class="flex justify-end gap-2 p-4 pt-0">
        <BaseButton variant="ghost" @click="onCancel">{{ cancelText }}</BaseButton>
        <BaseButton variant="secondary" @click="onConfirm">{{ confirmText }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>

<script setup>
import BaseModal from '../base/BaseModal.vue'
import BaseButton from '../base/BaseButton.vue'
defineProps({
  visible: { type: Boolean, default: false },
  title: { type: String, default: 'Confirm' },
  message: { type: String, default: 'Are you sure?' },
  confirmText: { type: String, default: 'Delete' },
  cancelText: { type: String, default: 'Cancel' }
})

const emit = defineEmits(['confirm', 'cancel'])

const onConfirm = () => emit('confirm')
const onCancel = () => emit('cancel')
</script>
