import { ref, watch, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { processSSEMessage, createSSEConnection } from '../utils/sseUtils'
import { createStalenessDetector } from '../utils/stalenessDetector'

export function useSSE(token, API_BASE, axios, currentSchedule, documentationCounts, lastSavingData, isEditing, showNotification, notification, isConnected, refreshSchedule) {
  const { t } = useI18n()
  const handleSSEMessage = (data) => {
    processSSEMessage(data, {
      currentSchedule,
      documentationCounts,
      lastSavingData,
      isEditing,
      showNotification,
      notification,
      t
    })
  }

  const { setupSSE, close } = createSSEConnection({
    token,
    apiBase: API_BASE,
    currentSchedule,
    isConnected,
    refreshSchedule,
    onMessage: handleSSEMessage,
    autoRefresh: ref(true),
    axios
  })

  const stalenessDetector = createStalenessDetector({
    onStale: async () => {
      await refreshSchedule()
      await setupSSE()
    }
  })

  watch(() => currentSchedule.value?.hashed_id, async (newId, oldId) => {
    if (newId) {
      stalenessDetector.start()
      if (newId !== oldId) {
        await setupSSE()
      }
    } else {
      stalenessDetector.stop()
      close()
    }
  }, { immediate: true })

  onUnmounted(() => {
    stalenessDetector.stop()
    close()
  })
}
