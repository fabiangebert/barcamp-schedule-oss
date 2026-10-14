<template>
  <BaseModal
:visible="visible"
:title="t('modals.newScheduleTitle')"
max-width="md"
@close="$emit('close')">
    <div class="space-y-4">
      <BaseInput
        v-model="internalValue"
        :label="t('common.name')"
        :placeholder="t('modals.newSchedulePlaceholder')"
        autofocus
        @enter="$emit('create')"
      />
      <div class="flex justify-end gap-3 pt-2">
        <BaseButton variant="ghost" @click="$emit('close')">
          {{ t('common.cancel') }}
        </BaseButton>
        <BaseButton @click="$emit('create')">
          {{ t('modals.create') }}
        </BaseButton>
      </div>
    </div>
  </BaseModal>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { computed } from 'vue'
import BaseModal from '../base/BaseModal.vue'
import BaseButton from '../base/BaseButton.vue'
import BaseInput from '../base/BaseInput.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  modelValue: { type: String, default: '' }
})

const emit = defineEmits(['update:modelValue', 'close', 'create'])

const internalValue = computed({
  get: () => props.modelValue,
  set: (val) => emit('update:modelValue', val)
})
</script>
