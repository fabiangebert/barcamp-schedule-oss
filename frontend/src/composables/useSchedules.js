import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { showApiError } from '../utils/notificationHelpers'

export function useSchedules(token, API_BASE, axios, showNotification, isConnected) {
  const { t } = useI18n()
  const allSchedules = ref([])
  const sortColumn = ref('date')
  const sortOrder = ref('desc')
  const deleteModalVisible = ref(false)
  const scheduleToDelete = ref(null)
  const scheduleNameToDelete = ref('')

  const fetchSchedules = async () => {
    if (!token.value) {
      return
    }
    try {
      const res = await axios.get(`${API_BASE}/schedules`, {
        headers: { Authorization: `Bearer ${token.value}` }
      })
      allSchedules.value = res.data.data
      isConnected.value = true
    } catch (err) {
      console.error('Failed to fetch schedules', err)
      if (!err.response) {
        isConnected.value = false
      }
    }
  }

  const sortedSchedules = computed(() => {
    return [...allSchedules.value].sort((a, b) => {
      let valA = a[sortColumn.value] || ''
      let valB = b[sortColumn.value] || ''
      if (valA < valB) return sortOrder.value === 'asc' ? -1 : 1
      if (valA > valB) return sortOrder.value === 'asc' ? 1 : -1
      return 0
    })
  })

  const toggleSort = (column) => {
    if (sortColumn.value === column) {
      sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
    } else {
      sortColumn.value = column
      sortOrder.value = 'asc'
    }
  }

  const requestDeleteSchedule = (s) => {
    scheduleToDelete.value = s.hashed_id
    scheduleNameToDelete.value = s.name
    deleteModalVisible.value = true
  }

  const cancelDeleteSchedule = () => {
    deleteModalVisible.value = false
    scheduleToDelete.value = null
    scheduleNameToDelete.value = ''
  }

  const confirmDeleteSchedule = async () => {
    if (!scheduleToDelete.value) return
    try {
      await axios.delete(`${API_BASE}/schedules/${scheduleToDelete.value}`, {
        headers: { Authorization: `Bearer ${token.value}` }
      })
      deleteModalVisible.value = false
      scheduleToDelete.value = null
      scheduleNameToDelete.value = ''
      await fetchSchedules()
    } catch (err) {
      showApiError(showNotification, t('notifications.error'), t('auth.failedToDeleteSchedule'), err)
    }
  }

  return {
    allSchedules,
    sortColumn,
    sortOrder,
    deleteModalVisible,
    scheduleToDelete,
    scheduleNameToDelete,
    fetchSchedules,
    sortedSchedules,
    toggleSort,
    requestDeleteSchedule,
    cancelDeleteSchedule,
    confirmDeleteSchedule
  }
}
