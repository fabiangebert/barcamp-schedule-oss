import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useScheduleStore = defineStore('schedule', () => {
  /** @type {import('vue').Ref<import('../types/schedule').Schedule | null>} */
  const schedule = ref(null)
  const isEditing = ref(false)
  const readOnly = ref(false)
  const showAdvancedControls = ref(false)
  /** @type {import('vue').Ref<import('../types/schedule').ActiveEdit | null>} */
  const activeEdit = ref(null)

  const isDimmed = (timeIndex, roomIndex, timeslotName, roomName, selectedTopic) => {
    const sessions = schedule.value?.data?.sessions || []
    const session = sessions.find((s) => s.timeslot === timeslotName && s.room === roomName)

    // Viewing mode: dim if it doesn't match selected topic
    if (!isEditing.value) {
      return !!(selectedTopic && session && session.topic !== selectedTopic)
    }

    // Editing mode: dim everything except what is being edited
    if (activeEdit.value) {
      const cellId = `${timeIndex}-${roomIndex}`
      return !isActiveEdit('session', cellId) &&
             !(activeEdit.value.type === 'session' && activeEdit.value.id === cellId)
    }

    return false
  }

  const isActiveEdit = (type, id, field = null) => {
    return activeEdit.value &&
        activeEdit.value.type === type &&
        activeEdit.value.id === id &&
        activeEdit.value.field === field
  }

  // Actions
  const setSchedule = (newSchedule) => {
    schedule.value = newSchedule
  }

  const updateScheduleData = (newData) => {
    if (!schedule.value) return
    schedule.value.data = { ...schedule.value.data, ...newData }
  }

  const startEditing = (type, id, field) => {
    activeEdit.value = { type, id, field }
  }

  const stopEditing = () => {
    activeEdit.value = null
  }

  return {
    schedule,
    isEditing,
    readOnly,
    showAdvancedControls,
    activeEdit,
    isDimmed,
    isActiveEdit,
    setSchedule,
    updateScheduleData,
    startEditing,
    stopEditing
  }
})
