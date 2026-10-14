<template>
  <BaseModal
    :visible="visible"
    :title="t('modals.shareTitle', { name: scheduleName })"
    max-width="2xl"
    @close="$emit('close')"
  >
    <!-- Tabs -->
    <div class="flex border-b border-gray-light/30 mb-6">
      <button
        class="px-4 py-2 text-sm font-bold uppercase tracking-wider transition-colors border-b-2"
        :class="activeTab === 'share' ? 'border-primary text-primary' : 'border-transparent text-gray-mid hover:text-gray-mid'"
        @click="activeTab = 'share'"
      >
        {{ t('modals.tabShareLink') }}
      </button>
      <button
        class="px-4 py-2 text-sm font-bold uppercase tracking-wider transition-colors border-b-2"
        :class="activeTab === 'embed' ? 'border-primary text-primary' : 'border-transparent text-gray-mid hover:text-gray-mid'"
        @click="activeTab = 'embed'"
      >
        {{ t('modals.tabEmbed') }}
      </button>
    </div>

    <!-- Tab Content -->
    <div v-if="activeTab === 'share'">
      <ShareLinkPanel
        :schedule-id="props.scheduleId"
        :token="props.token"
        :is-admin="props.isAdmin"
        :is-user="props.isUser"
        :show-notification="props.showNotification"
      />
    </div>
    <div v-else-if="activeTab === 'embed'">
      <EmbedPanel
        :schedule-id="props.scheduleId"
        :show-notification="props.showNotification"
      />
    </div>

    <template #footer>
      <div class="p-4 border-t border-gray-light/50 text-right">
        <BaseButton variant="ghost" @click="$emit('close')">{{ t('common.close') }}</BaseButton>
      </div>
    </template>
  </BaseModal>
</template>

<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseModal from '../base/BaseModal.vue'
import BaseButton from '../base/BaseButton.vue'
import ShareLinkPanel from '../share/ShareLinkPanel.vue'
import EmbedPanel from '../share/EmbedPanel.vue'

const { t } = useI18n()

const props = defineProps({
  visible: { type: Boolean, default: false },
  scheduleName: { type: String, default: '' },
  scheduleId: { type: String, default: '' },
  token: { type: String, default: '' },
  isAdmin: { type: Boolean, default: false },
  isUser: { type: Boolean, default: false },
  showNotification: { type: Function, default: null }
})

defineEmits(['close'])

const activeTab = ref('share')
</script>
