/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref, nextTick } from 'vue'

const mockStore = {
  activeEdit: ref(null),
  startEditing: vi.fn(),
  stopEditing: vi.fn(),
  isActiveEdit: vi.fn().mockReturnValue(false)
}

vi.mock('../src/stores/schedule', () => ({
  useScheduleStore: () => mockStore
}))

import { useGridEditing } from '../src/composables/useGridEditing'

describe('useGridEditing', () => {
  let isEditing
  let rooms
  let timeslots

  beforeEach(() => {
    vi.clearAllMocks()
    mockStore.activeEdit.value = null
    isEditing = ref(false)
    rooms = ref(['Room A', 'Room B'])
    timeslots = ref(['09:00', '10:00'])
  })

  describe('Escape key closes editing', () => {
    it('calls store.stopEditing on Escape when editing', async () => {
      vi.spyOn(window, 'addEventListener')

      useGridEditing(isEditing, rooms, timeslots)
      isEditing.value = true
      await nextTick()

      expect(window.addEventListener).toHaveBeenCalledWith('keydown', expect.any(Function))

      const handler = vi.mocked(window.addEventListener).mock.calls.find(c => c[0] === 'keydown')[1]
      handler({ key: 'Escape' })

      expect(mockStore.stopEditing).toHaveBeenCalled()
    })

    it('does not call stopEditing on non-Escape key', async () => {
      vi.spyOn(window, 'addEventListener')

      useGridEditing(isEditing, rooms, timeslots)
      isEditing.value = true
      await nextTick()

      const handler = vi.mocked(window.addEventListener).mock.calls.find(c => c[0] === 'keydown')[1]
      mockStore.stopEditing.mockClear()
      handler({ key: 'Enter' })

      expect(mockStore.stopEditing).not.toHaveBeenCalled()
    })

    it('stops editing when isEditing becomes false via watch', async () => {
      useGridEditing(isEditing, rooms, timeslots)

      expect(mockStore.stopEditing).toHaveBeenCalledTimes(1)
      mockStore.stopEditing.mockClear()

      isEditing.value = true
      await nextTick()

      expect(mockStore.stopEditing).not.toHaveBeenCalled()

      isEditing.value = false
      await nextTick()

      expect(mockStore.stopEditing).toHaveBeenCalledTimes(1)
    })
  })

  describe('startEditing', () => {
    it('calls store.startEditing and focuses element', async () => {
      document.body.innerHTML = '<div data-edit-id="session-0-0-title"></div>'

      const { startEditing } = useGridEditing(isEditing, rooms, timeslots)
      isEditing.value = true

      const el = document.querySelector('[data-edit-id="session-0-0-title"]')
      const focusSpy = vi.spyOn(el, 'focus')

      await startEditing('session', '0-0', 'title')

      expect(mockStore.startEditing).toHaveBeenCalledWith('session', '0-0', 'title')
      expect(focusSpy).toHaveBeenCalled()
    })
  })

  describe('stopEditing', () => {
    it('calls store.stopEditing when type and id match activeEdit', () => {
      mockStore.activeEdit.value = { type: 'session', id: '0-0', field: 'title' }

      const { stopEditing } = useGridEditing(isEditing, rooms, timeslots)
      stopEditing('session', '0-0', 'title')

      expect(mockStore.stopEditing).toHaveBeenCalled()
    })

    it('calls store.stopEditing when no args provided', () => {
      const { stopEditing } = useGridEditing(isEditing, rooms, timeslots)
      stopEditing()

      expect(mockStore.stopEditing).toHaveBeenCalled()
    })
  })

  describe('navigateNextField', () => {
    it('moves to next field on Tab', async () => {
      const { navigateNextField } = useGridEditing(isEditing, rooms, timeslots)
      const event = { shiftKey: false, target: { blur: vi.fn() } }

      await navigateNextField(event, 'session', '0-0', 'title')

      expect(mockStore.startEditing).toHaveBeenCalledWith('session', '0-0', 'speakers')
    })

    it('moves to previous field on Shift+Tab', async () => {
      const { navigateNextField } = useGridEditing(isEditing, rooms, timeslots)
      const event = { shiftKey: true, target: { blur: vi.fn() } }

      await navigateNextField(event, 'session', '0-0', 'speakers')

      expect(mockStore.startEditing).toHaveBeenCalledWith('session', '0-0', 'title')
    })

    it('moves to next cell when on last field', async () => {
      const { navigateNextField } = useGridEditing(isEditing, rooms, timeslots)
      const event = { shiftKey: false, target: { blur: vi.fn() } }

      await navigateNextField(event, 'session', '0-0', 'format')

      expect(mockStore.startEditing).toHaveBeenCalledWith('session', '0-1', 'title')
    })

    it('blurs when on first cell first field with Shift+Tab', async () => {
      const { navigateNextField } = useGridEditing(isEditing, rooms, timeslots)
      const blurFn = vi.fn()
      const event = { shiftKey: true, target: { blur: blurFn } }

      await navigateNextField(event, 'session', '0-0', 'title')

      expect(blurFn).toHaveBeenCalled()
    })

    it('does not navigate for non-session type', async () => {
      const { navigateNextField } = useGridEditing(isEditing, rooms, timeslots)
      const event = { shiftKey: false, target: { blur: vi.fn() } }

      await navigateNextField(event, 'row', '0', null)

      expect(mockStore.startEditing).not.toHaveBeenCalled()
    })
  })

  describe('isActiveEdit', () => {
    it('delegates to store', () => {
      const { isActiveEdit } = useGridEditing(isEditing, rooms, timeslots)
      isActiveEdit('session', '0-0', 'title')
      expect(mockStore.isActiveEdit).toHaveBeenCalledWith('session', '0-0', 'title')
    })
  })
})
