/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

import { useGridDragAndDrop } from '../src/composables/useGridDragAndDrop'

describe('useGridDragAndDrop', () => {
  let isEditing
  let rooms
  let timeslots
  let topics
  let updateSchedule
  let props
  let defaultValues
  let dnd

  beforeEach(() => {
    vi.clearAllMocks()
    isEditing = ref(true)
    rooms = ref(['Room A', 'Room B'])
    timeslots = ref(['09:00', '10:00'])
    topics = ref([{ name: 'Tech' }, { name: 'Design' }])
    updateSchedule = vi.fn()
    props = {
      schedule: ref({
        data: {
          sessions: [
            { timeslot: '09:00', room: 'Room A', title: 'S1' },
            { timeslot: '10:00', room: 'Room B', title: 'S2' }
          ],
          topics: [{ id: 't1', name: 'Tech' }, { id: 't2', name: 'Design' }]
        }
      })
    }
    defaultValues = { title: 'New Session', speakers: '', format: '' }
    dnd = useGridDragAndDrop(isEditing, rooms, timeslots, topics, updateSchedule, props, defaultValues)
  })

  describe('drag state management', () => {
    it('sets draggedRow on drag start for row type', () => {
      const event = {
        dataTransfer: { effectAllowed: '', setData: vi.fn() }
      }
      dnd.onDragStart('row', 0, event)
      expect(dnd.draggedRow.value).toBe(0)
    })

    it('sets draggedCol on drag start for column type', () => {
      const event = {
        dataTransfer: { effectAllowed: '', setData: vi.fn() }
      }
      dnd.onDragStart('column', 1, event)
      expect(dnd.draggedCol.value).toBe(1)
    })

    it('sets isDraggingSession on drag start for session type', () => {
      const event = {
        dataTransfer: { effectAllowed: '', setData: vi.fn() }
      }
      dnd.onDragStart('session', '0-0', event)
      expect(dnd.isDraggingSession.value).toBe(true)
    })

    it('clears all state on drag end', () => {
      dnd.draggedRow.value = 0
      dnd.draggedCol.value = 1
      dnd.isDraggingSession.value = true

      dnd.onDragEnd()

      expect(dnd.draggedRow.value).toBeNull()
      expect(dnd.draggedCol.value).toBeNull()
      expect(dnd.isDraggingSession.value).toBe(false)
    })

    it('does nothing when not editing', () => {
      isEditing.value = false
      const event = {
        dataTransfer: { effectAllowed: '', setData: vi.fn() }
      }
      dnd.onDragStart('row', 0, event)
      expect(dnd.draggedRow.value).toBeNull()
    })
  })

  describe('swap sessions', () => {
    it('swaps sessions between two cells', () => {
      const source = { timeslot: '09:00', room: 'Room A' }
      const target = { timeslot: '10:00', room: 'Room B' }

      const event = {
        dataTransfer: { getData: vi.fn((k) => k === 'type' ? 'session' : JSON.stringify(source)) },
        preventDefault: vi.fn()
      }

      dnd.onDrop('session', target, event)

      expect(updateSchedule).toHaveBeenCalled()
      const callArg = updateSchedule.mock.calls[0][0]
      const sessions = callArg.sessions
      expect(sessions.find(s => s.timeslot === '10:00' && s.room === 'Room B').title).toBe('S1')
      expect(sessions.find(s => s.timeslot === '09:00' && s.room === 'Room A').title).toBe('S2')
    })

    it('does nothing when dropping on same cell', () => {
      const source = { timeslot: '09:00', room: 'Room A' }
      const target = { timeslot: '09:00', room: 'Room A' }

      const event = {
        dataTransfer: { getData: vi.fn((k) => k === 'type' ? 'session' : JSON.stringify(source)) },
        preventDefault: vi.fn()
      }

      dnd.onDrop('session', target, event)

      expect(updateSchedule).not.toHaveBeenCalled()
    })
  })

  describe('swap rows (timeslots)', () => {
    it('swaps two timeslots', () => {
      const event = {
        dataTransfer: { getData: vi.fn((k) => k === 'type' ? 'row' : JSON.stringify(0)) },
        preventDefault: vi.fn()
      }

      dnd.onDrop('row', 1, event)

      expect(updateSchedule).toHaveBeenCalledWith({ timeslots: ['10:00', '09:00'] })
    })

    it('does nothing when dropping on same row', () => {
      const event = {
        dataTransfer: { getData: vi.fn((k) => k === 'type' ? 'row' : JSON.stringify(0)) },
        preventDefault: vi.fn()
      }

      dnd.onDrop('row', 0, event)

      expect(updateSchedule).not.toHaveBeenCalled()
    })
  })

  describe('swap columns (rooms)', () => {
    it('swaps two rooms', () => {
      const event = {
        dataTransfer: { getData: vi.fn((k) => k === 'type' ? 'column' : JSON.stringify(0)) },
        preventDefault: vi.fn()
      }

      dnd.onDrop('column', 1, event)

      expect(updateSchedule).toHaveBeenCalledWith({ rooms: ['Room B', 'Room A'] })
    })
  })

  describe('topic drag to session', () => {
    it('assigns topic to session', () => {
      const target = { timeslot: '09:00', room: 'Room A' }

      const event = {
        dataTransfer: { getData: vi.fn((k) => k === 'type' ? 'topic' : JSON.stringify(0)) },
        preventDefault: vi.fn()
      }

      dnd.onDrop('session', target, event)

      expect(updateSchedule).toHaveBeenCalled()
      const callArg = updateSchedule.mock.calls[0][0]
      expect(callArg.sessions[0].topic).toBe('Tech')
    })
  })

  describe('swap topics', () => {
    it('swaps two topics', () => {
      const event = {
        dataTransfer: { getData: vi.fn((k) => k === 'type' ? 'topic' : JSON.stringify(0)) },
        preventDefault: vi.fn()
      }

      dnd.onDrop('topic', 1, event)

      expect(updateSchedule).toHaveBeenCalled()
      const callArg = updateSchedule.mock.calls[0][0]
      expect(callArg.topics[0].name).toBe('Design')
      expect(callArg.topics[1].name).toBe('Tech')
    })
  })

  describe('dragOver tracking', () => {
    it('sets dragOverRow for row type', () => {
      const event = {
        dataTransfer: { dropEffect: '', effectAllowed: 'move' },
        preventDefault: vi.fn()
      }
      dnd.draggedRow.value = 0

      dnd.onDragOver('row', 2, event)
      expect(dnd.dragOverRow.value).toBe(2)
    })

    it('sets dragOverSession for session drag', () => {
      const event = {
        dataTransfer: { dropEffect: '', effectAllowed: 'move' },
        preventDefault: vi.fn()
      }
      dnd.isDraggingSession.value = true

      dnd.onDragOver('session', '1-1', event)
      expect(dnd.dragOverSession.value).toBe('1-1')
    })

    it('clears dragOver on dragLeave', () => {
      dnd.dragOverRow.value = 0
      dnd.dragOverSession.value = '1-1'

      dnd.onDragLeave()

      expect(dnd.dragOverRow.value).toBeNull()
      expect(dnd.dragOverSession.value).toBeNull()
    })
  })
})
