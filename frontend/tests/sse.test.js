import { describe, it, expect, vi } from 'vitest'
import { ref } from 'vue'
import { processSSEMessage } from '../src/utils/sseUtils'

// Mock i18n
vi.mock('../src/i18n', () => ({
  default: {
    global: {
      t: (key) => key
    }
  }
}))

describe('processSSEMessage', () => {
  const createMockContext = (initialSchedule = null) => {
    const currentSchedule = ref(initialSchedule ? JSON.parse(JSON.stringify(initialSchedule)) : null)
    const lastSavingData = ref(initialSchedule ? {
      data: JSON.parse(JSON.stringify(initialSchedule.data)),
      name: initialSchedule.name,
      date: initialSchedule.date
    } : null)
    const documentationCounts = ref({})
    const isEditing = ref(false)
    const lastEventId = ref('')
    const notification = ref({ visible: false })
    const showNotification = vi.fn()
    const t = (key) => key

    return {
      currentSchedule,
      lastSavingData,
      documentationCounts,
      isEditing,
      lastEventId,
      showNotification,
      notification,
      t
    }
  }

  it('updates schedule if version is higher', () => {
    const initial = { version: 1, data: { foo: 'bar' } }
    const ctx = createMockContext(initial)
    const update = {
      schedule: { version: 2, data: { foo: 'baz' } },
      lastEventId: 'evt-1'
    }

    processSSEMessage(JSON.stringify(update), ctx)

    expect(ctx.currentSchedule.value.version).toBe(2)
    expect(ctx.currentSchedule.value.data.foo).toBe('baz')
  })

  it('does not update schedule if version is lower or equal', () => {
    const initial = { version: 2, data: { foo: 'bar' } }
    const ctx = createMockContext(initial)
    const update = {
      schedule: { version: 2, data: { foo: 'baz' } }
    }

    processSSEMessage(JSON.stringify(update), ctx)

    expect(ctx.currentSchedule.value.version).toBe(2)
    expect(ctx.currentSchedule.value.data.foo).toBe('bar') // Should not change
  })

  it('updates documentation_counts independently if present', () => {
    const initial = { version: 2, data: { foo: 'bar' } }
    const ctx = createMockContext(initial)
    const update = {
      schedule: { version: 2, data: { foo: 'baz' } }, // same version
      documentation_counts: { 'session-1': 5 }
    }

    processSSEMessage(JSON.stringify(update), ctx)

    expect(ctx.currentSchedule.value.version).toBe(2)
    expect(ctx.currentSchedule.value.data.foo).toBe('bar') // data unchanged
    expect(ctx.documentationCounts.value).toEqual({ 'session-1': 5 })
  })

  it('shows notification if version is higher but there are local changes and editing', () => {
    const initial = { version: 1, data: { foo: 'bar' } }
    const ctx = createMockContext(initial)
    ctx.isEditing.value = true
    ctx.currentSchedule.value.data.foo = 'modified' // local change

    const update = {
      schedule: { version: 2, data: { foo: 'baz' } }
    }

    processSSEMessage(JSON.stringify(update), ctx)

    expect(ctx.currentSchedule.value.version).toBe(1) // Not updated yet
    expect(ctx.showNotification).toHaveBeenCalledWith(
      'notifications.updateAvailable',
      'notifications.updateAvailableMessage',
      'info',
      expect.any(Object)
    )
  })

  it('updates immediately if editing but no local changes', () => {
    const initial = { version: 1, data: { foo: 'bar' } }
    const ctx = createMockContext(initial)
    ctx.isEditing.value = true
    // no changes to ctx.currentSchedule.value.data

    const update = {
      schedule: { version: 2, data: { foo: 'baz' } }
    }

    processSSEMessage(JSON.stringify(update), ctx)

    expect(ctx.currentSchedule.value.version).toBe(2)
    expect(ctx.currentSchedule.value.data.foo).toBe('baz')
    expect(ctx.lastSavingData.value).toBeNull() // Cleared on success
  })

  it('updates lastSavingData when confirmed after local changes', () => {
    const initial = { version: 1, data: { foo: 'bar' } }
    const ctx = createMockContext(initial)
    ctx.isEditing.value = true
    ctx.currentSchedule.value.data.foo = 'modified'

    const update = {
      schedule: { version: 2, data: { foo: 'baz' } }
    }

    processSSEMessage(JSON.stringify(update), ctx)

    expect(ctx.showNotification).toHaveBeenCalled()
    const options = ctx.showNotification.mock.calls[0][3]
    options.onConfirm()

    expect(ctx.currentSchedule.value.version).toBe(2)
    expect(ctx.currentSchedule.value.data.foo).toBe('baz')
    expect(ctx.lastSavingData.value).toEqual({
      data: { foo: 'baz' },
      name: undefined,
      date: undefined
    })
  })

  it('handles 409 conflict scenario implicitly by update available notification', () => {
    // A 409 conflict at the server means the client's version is outdated.
    // The server then usually sends an SSE update with the current state.
    // This test ensures that when such an update arrives (higher version), 
    // we show the "Update Available" notification if there are local changes.
    const initial = { version: 5, data: { foo: 'bar' } }
    const ctx = createMockContext(initial)
    ctx.isEditing.value = true
    ctx.currentSchedule.value.data.foo = 'local edit'

    // Server is already at version 6 (which would cause a 409 if we tried to save version 5)
    const update = {
      schedule: { version: 6, data: { foo: 'server version' } }
    }

    processSSEMessage(JSON.stringify(update), ctx)

    expect(ctx.showNotification).toHaveBeenCalledWith(
      'notifications.updateAvailable',
      'notifications.updateAvailableMessage',
      'info',
      expect.any(Object)
    )
    expect(ctx.currentSchedule.value.version).toBe(5) // Still at 5 until confirmed
  })
})
