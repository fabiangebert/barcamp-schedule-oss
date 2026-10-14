<template>
  <BaseModal
:visible="visible"
:title="t('auth.welcome')"
:closable="showCancel"
@close="onCancel">
    <p class="text-sm text-gray-mid mb-6">
      {{ isNameCustomized ? t('auth.enterNameMessage') : t('auth.customizeNameMessage') }}
    </p>
    
    <form class="space-y-4" @submit.prevent="onConfirm">
      <BaseInput
        ref="nameInput"
        v-model="localName"
        :label="t('common.name')"
        :placeholder="t('documentation.enterYourName')"
        required
      />
      
      <div class="flex justify-end gap-3 pt-2">
        <BaseButton v-if="showCancel" variant="ghost" @click="onCancel">
          {{ t('common.cancel') }}
        </BaseButton>
        <BaseButton type="submit">
          {{ t('common.save') }}
        </BaseButton>
      </div>
    </form>
  </BaseModal>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref, watch, nextTick } from 'vue'
import BaseModal from '../base/BaseModal.vue'
import BaseButton from '../base/BaseButton.vue'
import BaseInput from '../base/BaseInput.vue'

const props = defineProps({
  visible: { type: Boolean, default: false },
  initialName: { type: String, default: '' },
  isNameCustomized: { type: Boolean, default: true },
  showCancel: { type: Boolean, default: true }
})

const emit = defineEmits(['confirm', 'cancel'])

const localName = ref(props.initialName)
const nameInput = ref(null)

watch(() => props.visible, (newVal) => {
  if (newVal) {
    localName.value = props.initialName
    nextTick(() => {
      if (nameInput.value) {
        nameInput.value.focus()
        nameInput.value.select()
      }
    })
  }
})

const onConfirm = () => {
  if (localName.value.trim()) {
    emit('confirm', localName.value.trim())
  }
}

const onCancel = () => emit('cancel')
</script>
