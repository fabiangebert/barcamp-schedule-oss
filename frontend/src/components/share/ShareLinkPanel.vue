<template>
  <div>
    <div class="mb-6">
      <h4 class="text-sm font-bold text-gray-mid uppercase tracking-wider mb-4">{{ t('modals.createNewShareLink') }}</h4>
      <div class="border border-gray-light/50 rounded-lg p-4 bg-surface-sunken flex flex-col sm:flex-row items-end gap-4">
        <div class="flex-1 w-full sm:w-auto">
          <label class="block text-3xs font-bold text-gray-mid uppercase tracking-wider mb-1">{{ t('modals.expiryDate') }}</label>
          <input
            v-model="newExpiryDate"
            type="date"
            class="bg-surface border border-gray-light/50 rounded px-3 py-1.5 text-xs focus:outline-none focus:border-primary w-full"
          />
        </div>
        <BaseButton
          :loading="creating"
          class="!px-6 !py-1.5 w-full sm:w-auto"
          @click="createLink"
        >
          {{ creating ? t('modals.creating') : t('modals.create') }}
        </BaseButton>
      </div>
      <p class="text-xs text-gray-mid mt-2">{{ t('modals.shareLinkDescription') }}</p>
    </div>

    <div v-if="loading" class="text-center py-4 text-gray-mid">{{ t('modals.loadingLinks') }}</div>
    <div v-else-if="links.length === 0" class="text-center py-4 text-gray-mid/60 italic">{{ t('modals.noLinks') }}</div>
    <div v-else class="space-y-4 mb-6">
      <h4 class="text-sm font-bold text-gray-mid uppercase tracking-wider">
        {{ (isAdmin || isUser) ? t('modals.allShareLinks') : t('modals.activeShareLinks') }}
      </h4>
      <div v-for="link in links" :key="link.id" class="border border-gray-light/50 rounded-lg p-4 bg-surface-sunken">
        <div class="flex flex-col gap-2">
          <div class="flex justify-between items-start">
            <span class="text-xs font-mono bg-surface border border-gray-light/50 px-2 py-1 rounded truncate flex-1 mr-2">
              {{ getFullUrl(link.token) }}
            </span>
            <BaseButton 
              variant="ghost"
              class="!px-2 !py-1"
              @click="copyToClipboard(getFullUrl(link.token))"
            >
              {{ t('modals.copy') }}
            </BaseButton>
          </div>
          <div class="flex justify-between items-center mt-2">
            <div class="text-3xs text-gray-mid">
              <div v-if="(isAdmin || isUser) && link.created_by" class="mb-1 font-bold">
                {{ t('modals.createdBy') }}: {{ link.created_by }}
              </div>
              <div>{{ t('modals.created') }}: {{ formatDate(link.created_at) }}</div>
              <div v-if="editingLinkExpiry === link.id" class="mt-1 flex gap-2">
                <input 
                  v-model="editExpiryDate" 
                  type="date"
                  class="bg-surface border border-gray-light rounded px-1 py-0.5 text-3xs focus:outline-none focus:border-primary"
                />
                <button class="text-primary hover:underline" @click="saveExpiry(link.id)">{{ t('modals.saveExpiry') }}</button>
                <button class="text-gray-mid hover:underline" @click="editingLinkExpiry = null">{{ t('common.cancel') }}</button>
              </div>
              <div v-else :class="{'text-secondary': isExpired(link.expires_at)}" class="flex items-center gap-2">
                <span>{{ t('modals.expires') }}: {{ formatDate(link.expires_at) }}</span>
                <button class="hover:text-primary transition-colors" @click="startEditExpiry(link)">
                  <PencilIcon class="h-3 w-3" />
                </button>
              </div>
            </div>
            <button 
              class="text-xs text-secondary hover:underline"
              @click="deleteLink(link.id)"
            >
              {{ t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { API_BASE } from '../../constants'
import { parseStoredDate, toUtcDbString } from '../../utils/datetime.js'
import BaseButton from '../base/BaseButton.vue'
import PencilIcon from '../icons/PencilIcon.vue'

const { t } = useI18n()

const props = defineProps({
  scheduleId: { type: String, required: true },
  token: { type: String, required: true },
  isAdmin: { type: Boolean, default: false },
  isUser: { type: Boolean, default: false },
  showNotification: { type: Function, default: null }
})

const links = ref([])
const loading = ref(false)
const creating = ref(false)

const getDefaultExpiry = () => {
  const date = new Date()
  date.setDate(date.getDate() + 7)
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const newExpiryDate = ref(getDefaultExpiry())
const editingLinkExpiry = ref(null)
const editExpiryDate = ref('')

const fetchLinks = async () => {
  if (!props.token || !props.scheduleId) return
  loading.value = true
  try {
    const res = await axios.get(`${API_BASE}/share-links`, {
      params: { schedule_id: props.scheduleId },
      headers: { Authorization: `Bearer ${props.token}` }
    })
    links.value = res.data?.data || []
  } catch (err) {
    console.error('Failed to fetch share links', err)
  } finally {
    loading.value = false
  }
}

const createLink = async () => {
  creating.value = true
  try {
    const expiresAt = newExpiryDate.value ? toEndOfDayUtc(newExpiryDate.value) : null
    
    await axios.post(`${API_BASE}/share-links`, {
      schedule_id: props.scheduleId,
      expires_at: expiresAt
    }, {
      headers: { Authorization: `Bearer ${props.token}` }
    })
    await fetchLinks()
  } catch (err) {
    const msg = 'Failed to create share link: ' + (err.response?.data?.error || err.message)
    if (props.showNotification) {
      props.showNotification('Error', msg, 'error')
    }
  } finally {
    creating.value = false
  }
}

const deleteLink = async (id) => {
  if (!confirm(t('modals.deleteShareLinkConfirm'))) return
  try {
    await axios.delete(`${API_BASE}/share-links/${id}`, {
      headers: { Authorization: `Bearer ${props.token}` }
    })
    await fetchLinks()
  } catch (err) {
    const msg = 'Failed to delete share link: ' + (err.response?.data?.error || err.message)
    if (props.showNotification) {
      props.showNotification('Error', msg, 'error')
    }
  }
}

const getLocalDateInputValue = (dateStr) => {
  const date = parseStoredDate(dateStr)
  if (!date || isNaN(date.getTime())) return ''
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const toEndOfDayUtc = (dateStr) => {
  return toUtcDbString(new Date(`${dateStr}T23:59:59`))
}

const startEditExpiry = (link) => {
  editingLinkExpiry.value = link.id
  editExpiryDate.value = getLocalDateInputValue(link.expires_at)
}

const saveExpiry = async (id) => {
  try {
    const expiresAt = toEndOfDayUtc(editExpiryDate.value)
    await axios.patch(`${API_BASE}/share-links/${id}`, {
      expires_at: expiresAt
    }, {
      headers: { Authorization: `Bearer ${props.token}` }
    })
    editingLinkExpiry.value = null
    await fetchLinks()
  } catch (err) {
    const msg = 'Failed to update expiry date: ' + (err.response?.data?.error || err.message)
    if (props.showNotification) {
      props.showNotification('Error', msg, 'error')
    }
  }
}

const getFullUrl = (shareToken) => {
  const url = new URL(window.location.origin + window.location.pathname)
  url.hash = `share_token=${shareToken}`
  return url.toString()
}

const copyToClipboard = (text) => {
  navigator.clipboard.writeText(text).then(() => {
    if (props.showNotification) {
      props.showNotification(t('modals.linkCopied'), t('modals.linkCopiedMessage'), 'success')
    }
  })
}

const formatDate = (dateStr) => {
  const date = parseStoredDate(dateStr)
  return date ? date.toLocaleString() : ''
}

const isExpired = (dateStr) => {
  const date = parseStoredDate(dateStr)
  return date ? date < new Date() : false
}

onMounted(() => {
  fetchLinks()
})

watch(() => props.scheduleId, () => {
  fetchLinks()
})
</script>
