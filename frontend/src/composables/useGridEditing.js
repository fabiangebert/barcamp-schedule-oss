import { watch, nextTick, computed } from 'vue'
import { useScheduleStore } from '../stores/schedule'

export function useGridEditing(isEditing, rooms, timeslots) {
  const store = useScheduleStore()
  const activeEdit = computed(() => store.activeEdit)

  const onKeyDown = (event) => {
    if (event.key === 'Escape' && store.activeEdit) {
      store.stopEditing()
    }
  }

  watch(isEditing, (newVal) => {
    if (newVal) {
      window.addEventListener('keydown', onKeyDown)
    } else {
      window.removeEventListener('keydown', onKeyDown)
      store.stopEditing()
    }
  }, { immediate: true })

  const startEditing = async (type, id, field, event) => {
    store.startEditing(type, id, field)
    await nextTick()
    const el = event?.target || document.querySelector(`[data-edit-id="${type}-${id}${field ? '-' + field : ''}"]`)
    if (el) {
      el.focus()
    }
  }

  const stopEditing = (type, id, field) => {
    if (store.activeEdit && type && id) {
      if (store.activeEdit.type === type &&
          store.activeEdit.id === id &&
          store.activeEdit.field === field) {
        store.stopEditing()
      }
    } else {
      store.stopEditing()
    }
  }

  const navigateNextField = async (event, type, id, field) => {
    if (type !== 'session') return

    const [timeIndex, roomIndex] = id.split('-').map(Number)
    const fields = ['title', 'speakers', 'format']
    const currentFieldIndex = fields.indexOf(field)

    let nextType = 'session'
    let nextId = id
    let nextField = ''

    if (event.shiftKey) {
      if (currentFieldIndex > 0) {
        nextField = fields[currentFieldIndex - 1]
      } else {
        let prevRoomIndex = roomIndex - 1
        let prevTimeIndex = timeIndex
        if (prevRoomIndex < 0) {
          prevRoomIndex = rooms.value.length - 1
          prevTimeIndex--
        }
        if (prevTimeIndex >= 0) {
          nextId = `${prevTimeIndex}-${prevRoomIndex}`
          nextField = fields[fields.length - 1]
        }
      }
    } else {
      if (currentFieldIndex < fields.length - 1) {
        nextField = fields[currentFieldIndex + 1]
      } else {
        let nextRoomIndex = roomIndex + 1
        let nextTimeIndex = timeIndex
        if (nextRoomIndex >= rooms.value.length) {
          nextRoomIndex = 0
          nextTimeIndex++
        }
        if (nextTimeIndex < timeslots.value.length) {
          nextId = `${nextTimeIndex}-${nextRoomIndex}`
          nextField = fields[0]
        }
      }
    }

    if (nextField) {
      await startEditing(nextType, nextId, nextField)
    } else {
      event.target.blur()
    }
  }

  const onFocus = (event) => {
    const el = event.target
    const range = document.createRange()
    const sel = window.getSelection()
    range.selectNodeContents(el)
    sel.removeAllRanges()
    sel.addRange(range)
  }

  const onGridMouseDown = (event) => {
    if (!isEditing.value) return
    if (!event.target.hasAttribute('contenteditable') &&
        !event.target.closest('[data-edit-id]') &&
        !event.target.closest('button') &&
        !event.target.closest('.color-picker-popover') &&
        !event.target.closest('.topic-dropdown') &&
        !event.target.closest('input')) {
      store.stopEditing()
    }
  }

  const isActiveEdit = (type, id, field = null) => {
    return store.isActiveEdit(type, id, field)
  }

  return {
    activeEdit,
    startEditing,
    stopEditing,
    navigateNextField,
    onFocus,
    onGridMouseDown,
    isActiveEdit
  }
}
