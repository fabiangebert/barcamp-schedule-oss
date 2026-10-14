import { useI18n } from 'vue-i18n'
import { showVersionMismatchNotification, showApiError } from '../utils/notificationHelpers'

/**
 * Provides fetchSchedule and saveSchedule actions that communicate with the API.
 *
 * @param {import('vue').Ref<string | null>} token - JWT bearer token
 * @param {string} API_BASE - Base URL for API requests (e.g. '/api')
 * @param {import('axios').AxiosInstance} axios
 * @param {Function} showNotification
 * @param {Function} logout
 * @param {{
 *   currentSchedule: import('vue').Ref<import('../types/schedule').Schedule | null>,
 *   lastSavingData: import('vue').Ref<{data: import('../types/schedule').ScheduleData, name: string, date?: string|null}|null>,
 *   documentationCounts: import('vue').Ref<import('../types/schedule').DocumentationCount[]>,
 *   isConnected: import('vue').Ref<boolean>,
 *   closeNotification: Function,
 *   isGuest?: import('vue').Ref<boolean>
 * }} deps
 * @returns {{ fetchSchedule: Function, saveSchedule: Function }}
 */
export function useScheduleHandling(token, API_BASE, axios, showNotification, logout, { currentSchedule, lastSavingData, documentationCounts, isConnected, closeNotification, isGuest }) {
  const { t } = useI18n()

  const fetchSchedule = async (id, options = {}) => {
    if (!token.value) return

    try {
      const res = await axios.get(`${API_BASE}/schedules/${id}`, {
        headers: { Authorization: `Bearer ${token.value}` }
      })
      currentSchedule.value = res.data.data
      documentationCounts.value = res.data.documentation_counts
      // Clean copy of the data to detect local changes
      lastSavingData.value = {
        data: JSON.parse(JSON.stringify(currentSchedule.value.data)),
        name: currentSchedule.value.name,
        date: currentSchedule.value.date
      }
      isConnected.value = true

      if (options.onSuccess) options.onSuccess(res.data)
    } catch (err) {
      if (err.response && err.response.status === 404) {
        if (isGuest?.value) {
          showNotification(t('notifications.error'), t('auth.scheduleNotFound', { name: id }), 'error', {
            closable: false,
            confirmText: t('navbar.logout'),
            onConfirm: () => {
              closeNotification()
              logout()
            }
          })
        } else {
          showNotification(t('notifications.error'), t('auth.scheduleNotFound', { name: id }), 'error')
        }
        currentSchedule.value = null
        if (options.onNotFound) options.onNotFound()
      } else {
        showApiError(showNotification, t('notifications.error'), t('auth.failedToLoadSchedule'), err)
        if (!err.response) {
          isConnected.value = false
        }
      }
    }
  }

  const saveSchedule = async (saveStatus) => {
    if (!currentSchedule.value) return
    if (saveStatus.value === 'saving') return

    saveStatus.value = 'saving'

    while (true) {
      const snapshotData = JSON.parse(JSON.stringify(currentSchedule.value.data))
      const snapshotName = currentSchedule.value.name
      const snapshotDate = currentSchedule.value.date
      lastSavingData.value = {
        data: snapshotData,
        name: snapshotName,
        date: snapshotDate
      }
      try {
        const res = await axios.patch(`${API_BASE}/schedules/${currentSchedule.value.hashed_id}`, {
          data: snapshotData,
          version: currentSchedule.value.version,
          date: snapshotDate,
          name: snapshotName
        }, {
          headers: { Authorization: `Bearer ${token.value}` }
        })

        //it can be that SSE update handler has already updated the saved schedule

        const hasPendingChanges =
          JSON.stringify(currentSchedule.value.data) !== JSON.stringify(snapshotData) ||
          currentSchedule.value.name !== snapshotName ||
          currentSchedule.value.date !== snapshotDate

        if (hasPendingChanges) {
          // Edits arrived during save: update version/metadata from server, keep pending edits,
          // then loop to send them immediately
          currentSchedule.value = { ...res.data.data, data: currentSchedule.value.data }
        } else {
          currentSchedule.value = res.data.data
          lastSavingData.value = null
          saveStatus.value = 'saved'
          setTimeout(() => {
            if (saveStatus.value === 'saved') saveStatus.value = ''
          }, 3000)
          break
        }
      } catch (err) {
        saveStatus.value = 'error'
        let errorMessage = err.response ? (err.response.data?.error || err.message) : t('schedule.noInternet')
        if (err.response?.status === 409) {
          const newSchedule = err.response.data.current_schedule
          showVersionMismatchNotification(
            showNotification,
            t('notifications.updateAvailable'),
            t('notifications.updateAvailableMessage'),
            t('schedule.refresh'),
            t('schedule.ignore'),
            currentSchedule,
            lastSavingData,
            newSchedule
          );
        } else if (err.response?.status === 400 && err.response.data?.error === 'Schedule name already exists') {
          const newSchedule = err.response.data.current_schedule
          showNotification(t('notifications.error'), t('schedule.failedToSave', { error: errorMessage }), 'error', {
            closable: false,
            confirmText: t('common.retry'),
            cancelText: t('common.discard'),
            onConfirm: () => {
              closeNotification();
              saveSchedule(saveStatus);
            },
            onCancel: () => {
              if (newSchedule) {
                currentSchedule.value = newSchedule;
                lastSavingData.value = {
                  data: JSON.parse(JSON.stringify(currentSchedule.value.data)),
                  name: currentSchedule.value.name,
                  date: currentSchedule.value.date
                };
              }
              saveStatus.value = '';
              closeNotification();
            }
          })
        } else {
          showNotification(t('notifications.error'), t('schedule.failedToSave', { error: errorMessage }), 'error')
        }
        break
      }
    }
  }

  return {
    fetchSchedule,
    saveSchedule
  }
}
