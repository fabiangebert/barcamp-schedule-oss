/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'

// Mock vue-i18n
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key) => key
  })
}))

import { useScheduleHandling } from '../src/composables/useScheduleHandling'

describe('useScheduleHandling', () => {
  let token
  let API_BASE
  let axios
  let showNotification
  let logout

  beforeEach(() => {
    token = ref('test-token')
    API_BASE = '/api'
    axios = {
      get: vi.fn(),
      patch: vi.fn()
    }
    showNotification = vi.fn()
    logout = vi.fn()
  })

  it('fetchSchedule sets lastSavingData correctly', async () => {
    const scheduleData = { data: { foo: 'bar' }, version: 1 }
    axios.get.mockResolvedValue({
      data: {
        data: scheduleData,
        documentation_counts: []
      }
    })

    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)

    const { fetchSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, {
      currentSchedule,
      lastSavingData,
      documentationCounts,
      isConnected
    })

    await fetchSchedule('test-id')

    expect(currentSchedule.value).toEqual(scheduleData)
    expect(lastSavingData.value).toEqual({
      data: scheduleData.data,
      name: scheduleData.name,
      date: scheduleData.date
    })
    // Verify it's a copy, not the same reference
    expect(lastSavingData.value.data).not.toBe(currentSchedule.value.data)
  })

  it('saveSchedule updates lastSavingData correctly', async () => {
    const initialData = { hashed_id: 'test-id', data: { foo: 'bar' }, version: 1 }
    const updatedData = { hashed_id: 'test-id', data: { foo: 'baz' }, version: 2 }
    
    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)
    const { saveSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, { currentSchedule, lastSavingData, documentationCounts, isConnected })
    
    currentSchedule.value = initialData
    lastSavingData.value = {
      data: JSON.parse(JSON.stringify(initialData.data)),
      name: initialData.name,
      date: initialData.date
    }
    
    axios.patch.mockResolvedValue({
      data: {
        data: updatedData
      }
    })

    const saveStatus = ref('')
    await saveSchedule(saveStatus)

    expect(currentSchedule.value).toEqual(updatedData)
    expect(lastSavingData.value).toBeNull()
    expect(saveStatus.value).toBe('saved')
  })

  it('saveSchedule does not call API if already saving', async () => {
    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)
    const { saveSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, { currentSchedule, lastSavingData, documentationCounts, isConnected })
    currentSchedule.value = { hashed_id: 'test-id', data: {}, version: 1 }
    
    const saveStatus = ref('saving')
    await saveSchedule(saveStatus)
    
    expect(axios.patch).not.toHaveBeenCalled()
  })

  it('queues and submits pending changes after current save finishes', async () => {
    const initialData = { hashed_id: 'test-id', data: { val: 1 }, version: 1 }
    const firstSaveResponse = { hashed_id: 'test-id', data: { val: 1 }, version: 2 }
    const secondSaveResponse = { hashed_id: 'test-id', data: { val: 2 }, version: 3 }

    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)
    const { saveSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, { currentSchedule, lastSavingData, documentationCounts, isConnected })
    currentSchedule.value = JSON.parse(JSON.stringify(initialData))
    
    axios.patch
      .mockResolvedValueOnce({ data: { data: firstSaveResponse } })
      .mockResolvedValueOnce({ data: { data: secondSaveResponse } })

    const saveStatus = ref('')
    
    // Modify currentSchedule.value.data while the first save is "in progress"
    // In our mock, axios.patch is already resolved, but the loop in saveSchedule will check for differences.
    // However, saveSchedule captures snapshotData BEFORE axios.patch call.
    // So we need a way to delay the first axios.patch response or something.
    
    // Wait, let's use a manual promise for the first call
    let resolveFirstPatch
    const firstPatchPromise = new Promise(resolve => {
      resolveFirstPatch = () => resolve({ data: { data: firstSaveResponse } })
    })
    axios.patch.mockReturnValueOnce(firstPatchPromise)
    
    const savePromise = saveSchedule(saveStatus)
    
    // Now the first save is stuck waiting for firstPatchPromise
    expect(saveStatus.value).toBe('saving')
    
    // Change data while saving
    currentSchedule.value.data.val = 2
    
    // Resolve the first patch
    resolveFirstPatch()
    await firstPatchPromise
    
    // Now saveSchedule should continue, see that currentSchedule.value.data has changed from snapshotData,
    // and loop to save again.
    
    await savePromise
    
    expect(axios.patch).toHaveBeenCalledTimes(2)
    
    // Second call should have used the NEW version (2) and NEW data ({val: 2})
    expect(axios.patch).toHaveBeenNthCalledWith(2, expect.anything(), expect.objectContaining({
      version: 2,
      data: { val: 2 }
    }), expect.anything())
    
    expect(currentSchedule.value.version).toBe(3)
    expect(saveStatus.value).toBe('saved')
  })

  it('correctly uses the version from the first successful save in the next queued save', async () => {
    const initialData = { hashed_id: 'test-id', data: { val: 1 }, version: 10 }
    const firstSaveResponse = { hashed_id: 'test-id', data: { val: 1 }, version: 11 }
    const secondSaveResponse = { hashed_id: 'test-id', data: { val: 2 }, version: 12 }

    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)
    const { saveSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, { currentSchedule, lastSavingData, documentationCounts, isConnected })
    currentSchedule.value = JSON.parse(JSON.stringify(initialData))
    
    let resolveFirstPatch
    const firstPatchPromise = new Promise(resolve => {
      resolveFirstPatch = () => resolve({ data: { data: firstSaveResponse } })
    })
    axios.patch.mockReturnValueOnce(firstPatchPromise)
    axios.patch.mockResolvedValueOnce({ data: { data: secondSaveResponse } })

    const saveStatus = ref('')
    const savePromise = saveSchedule(saveStatus)
    
    // Trigger pending change
    currentSchedule.value.data.val = 2
    
    resolveFirstPatch()
    await savePromise
    
    expect(axios.patch).toHaveBeenCalledTimes(2)
    // Check first call version
    expect(axios.patch).toHaveBeenNthCalledWith(1, expect.anything(), expect.objectContaining({
      version: 10
    }), expect.anything())
    // Check second call version - MUST be 11, which was returned by the first call
    expect(axios.patch).toHaveBeenNthCalledWith(2, expect.anything(), expect.objectContaining({
      version: 11
    }), expect.anything())
  })

  it('handles 401 on fetch by rejecting the promise', async () => {
    axios.get.mockRejectedValue({
      response: { status: 401 }
    })

    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)
    const { fetchSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, { currentSchedule, lastSavingData, documentationCounts, isConnected })

    try {
      await fetchSchedule('test-id')
    } finally {
      // expected
    }
    expect(logout).not.toHaveBeenCalled()
  })

  it('handles 404 on fetch by showing notification', async () => {
    axios.get.mockRejectedValue({
      response: { status: 404 }
    })

    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)
    const { fetchSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, { currentSchedule, lastSavingData, documentationCounts, isConnected })

    await fetchSchedule('test-id')

    expect(showNotification).toHaveBeenCalledWith('notifications.error', expect.any(String), 'error')
    expect(currentSchedule.value).toBeNull()
  })

  it('handles 404 as guest by showing non-closable logout notification', async () => {
    axios.get.mockRejectedValue({
      response: { status: 404 }
    })

    const currentSchedule = ref(null)
    const lastSavingData = ref(null)
    const documentationCounts = ref([])
    const isConnected = ref(true)
    const isGuest = ref(true)
    const closeNotification = vi.fn()

    const { fetchSchedule } = useScheduleHandling(token, API_BASE, axios, showNotification, logout, {
      currentSchedule,
      lastSavingData,
      documentationCounts,
      isConnected,
      isGuest,
      closeNotification
    })

    await fetchSchedule('test-id')

    expect(showNotification).toHaveBeenCalledWith(
      'notifications.error',
      'auth.scheduleNotFound',
      'error',
      expect.objectContaining({
        closable: false,
        confirmText: 'navbar.logout'
      })
    )
    expect(currentSchedule.value).toBeNull()

    // Test onConfirm
    const options = showNotification.mock.calls[0][3]
    options.onConfirm()
    expect(closeNotification).toHaveBeenCalled()
    expect(logout).toHaveBeenCalled()
  })
})
