import { showVersionMismatchNotification } from './notificationHelpers'

export function processSSEMessage(data, { 
  currentSchedule, 
  documentationCounts, 
  lastSavingData, 
  isEditing, 
  showNotification, 
  notification,
  t
}) {
  try {
    const response = typeof data === 'string' ? JSON.parse(data) : data
    const newSchedule = response.schedule
    const newDocumentationCounts = response.documentation_counts

    if (newDocumentationCounts) {
      documentationCounts.value = newDocumentationCounts
    }

    if (newSchedule) {
      const currentVersion = currentSchedule.value?.version || 0
      const newVersion = newSchedule.version || 0

      // When editing, only consider schedule data update if the version is higher
      if (newVersion > currentVersion) {
        const hasLocalChanges = lastSavingData.value !== null && (
          JSON.stringify(lastSavingData.value.data) !== JSON.stringify(currentSchedule.value?.data) ||
          lastSavingData.value.name !== currentSchedule.value?.name ||
          lastSavingData.value.date !== currentSchedule.value?.date
        )
        if (!isEditing.value || !hasLocalChanges) {
          //this was either a new version in response to a save (SSE update came before save response)
          //or the user is not editing, so we can safely overwrite the schedule data
          currentSchedule.value = newSchedule
          //we're not saving any more, so we can clear the local data
          lastSavingData.value = null
        } else {
          showVersionMismatchNotification(
            showNotification,
            t('notifications.updateAvailable'),
            t('notifications.updateAvailableMessage'),
            t('schedule.refresh'),
            t('schedule.ignore'),
            currentSchedule,
            lastSavingData,
            newSchedule,
            notification
          )
        }
      }
    }
  } catch (err) {
    console.error("Failed to parse SSE data:", err)
  }
}

export function createSSEConnection({
  token,
  apiBase,
  currentSchedule,
  isConnected,
  refreshSchedule,
  onMessage,
  reconnectDelay = 10000,
  reconnectErrorDelay = 5000,
  autoRefresh,
  EventSourceClass = globalThis.EventSource,
  axios
}) {
  let sseSource = null
  let isConnecting = false
  let reconnectTimeout = null
  let wasClosedBySetupSSE = false

  const scheduleReconnect = (delay) => {
    if (reconnectTimeout) clearTimeout(reconnectTimeout)
    reconnectTimeout = setTimeout(async () => {
      if (autoRefresh.value) {
        await setupSSE()
      }
    }, delay)
  }

  const sseOnOpen = () => {
    isConnected.value = true
    if (refreshSchedule && currentSchedule.value?.hashed_id && wasClosedBySetupSSE) {
      refreshSchedule()
    }
    wasClosedBySetupSSE = false
  }

  const sseOnMessage = async (event) => {
    isConnected.value = true

    if (event.data) {
      onMessage(event.data)
    }
  }

  const sseOnError = () => {
    if (sseSource && sseSource.readyState === EventSourceClass.CLOSED) {
      isConnected.value = false
      scheduleReconnect(reconnectErrorDelay)
    }
  }

  const setupSSE = async () => {
    if (!autoRefresh.value || !currentSchedule.value) return
    if (isConnecting) {
      return
    }

    isConnecting = true
    try {
      if (sseSource) {
        wasClosedBySetupSSE = true
        sseSource.close()
        sseSource = null
      }

      const response = await axios.get(`${apiBase}/schedules/subscription`, {
        params: { schedule_id: currentSchedule.value.hashed_id },
        headers: {
          'Authorization': `Bearer ${token.value}`
        }
      })
      const result = response.data
      if (result.status !== 'success') {
        console.error(result.error || 'Failed to get subscription')
        isConnected.value = false
        scheduleReconnect(reconnectDelay)
        return
      }

      const { url, token: mercureToken } = result.data
      let finalUrl = url

      // Add token to URL for native EventSource
      finalUrl += (finalUrl.includes('?') ? '&' : '?') + `authorization=${mercureToken}`

      sseSource = new EventSourceClass(finalUrl)
      sseSource.onopen = sseOnOpen
      sseSource.onmessage = sseOnMessage
      sseSource.onerror = sseOnError
    } catch (err) {
      console.error('SSE connection error:', err)
      isConnected.value = false
      scheduleReconnect(reconnectDelay)
    } finally {
      isConnecting = false
    }
  }

  const close = () => {
    if (reconnectTimeout) {
      clearTimeout(reconnectTimeout)
      reconnectTimeout = null
    }
    if (sseSource) {
      sseSource.close()
      sseSource = null
    }
    isConnected.value = false
  }

  return {
    setupSSE,
    close
  }
}
