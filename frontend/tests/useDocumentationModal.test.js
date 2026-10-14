/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'

vi.mock('axios', () => {
  const mockAxios = {
    get: vi.fn(),
    post: vi.fn(),
    delete: vi.fn()
  }
  return { default: mockAxios }
})

import axios from 'axios'
import { useDocumentationModal } from '../src/composables/useDocumentationModal'

describe('useDocumentationModal', () => {
  let props
  let emit
  let getEndpointParts
  let modal

  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()

    props = {
      scheduleId: 'sched-1',
      token: 'test-token',
      isGuest: false,
      guestId: '',
      userName: 'Test User',
      documentationCounts: null
    }
    emit = vi.fn()
    getEndpointParts = vi.fn(() => ({ timeslot: '09:00', room: 'Room A' }))

    modal = useDocumentationModal(props, emit, getEndpointParts, (key, params) => {
      const translations = {
        'documentation.upload.unsupportedType': 'Unsupported type',
        'documentation.deleteConfirm': 'Are you sure?',
      }
      let msg = translations[key] || key
      if (params) {
        Object.entries(params).forEach(([k, v]) => { msg = msg.replace(`{${k}}`, v) })
      }
      return msg
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  describe('initial state', () => {
    it('starts with loading true and empty docs', () => {
      expect(modal.docs.value).toEqual([])
      expect(modal.loading.value).toBe(true)
      expect(modal.submitting.value).toBe(false)
      expect(modal.newComment.value).toBe('')
    })
  })

  describe('fetchDocs', () => {
    it('fetches documentation for the given session', async () => {
      axios.get.mockResolvedValue({
        data: { data: [{ id: 1, content: 'Hello' }] }
      })

      await modal.fetchDocs()

      expect(axios.get).toHaveBeenCalledWith(
        expect.stringContaining('/api/schedules/sched-1/sessions/'),
        expect.objectContaining({
          headers: { Authorization: 'Bearer test-token' }
        })
      )
      expect(modal.docs.value).toEqual([{ id: 1, content: 'Hello' }])
      expect(modal.loading.value).toBe(false)
    })

    it('sends X-Guest-Id header when isGuest is true', async () => {
      props.isGuest = true
      props.guestId = 'guest-123'

      axios.get.mockResolvedValue({ data: { data: [] } })

      await modal.fetchDocs()

      expect(axios.get).toHaveBeenCalledWith(
        expect.any(String),
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Guest-Id': 'guest-123'
          })
        })
      )
    })

    it('handles fetch error gracefully', async () => {
      axios.get.mockRejectedValue(new Error('Network error'))

      await modal.fetchDocs()

      expect(modal.docs.value).toEqual([])
      expect(modal.loading.value).toBe(false)
    })
  })

  describe('isPublicContribution', () => {
    it('is true for non-guest users', () => {
      props.isGuest = false
      expect(modal.isPublicContribution.value).toBe(true)
    })

    it('is true for guest at non-event session', () => {
      props.isGuest = true
      getEndpointParts.mockReturnValue({ timeslot: '10:00', room: 'Room B' })
      expect(modal.isPublicContribution.value).toBe(true)
    })

    it('is false for guest at event feedback', () => {
      props.isGuest = true
      getEndpointParts.mockReturnValue({ timeslot: 'event', room: 'event' })
      expect(modal.isPublicContribution.value).toBe(false)
    })
  })

  describe('handleImageUpload', () => {
    it('adds valid image file to selectedImages', () => {
      const file = new File([''], 'test.png', { type: 'image/png' })
      const event = { target: { files: [file] } }

      modal.handleImageUpload(event)

      expect(modal.selectedImages.value).toHaveLength(1)
      expect(modal.selectedImages.value[0].file).toBe(file)
      expect(modal.selectedImages.value[0].uploading).toBe(false)
    })

    it('rejects unsupported file type', () => {
      const file = new File([''], 'test.exe', { type: 'application/x-msdownload' })
      const event = { target: { files: [file] } }

      modal.handleImageUpload(event)

      expect(modal.selectedImages.value).toHaveLength(0)
      expect(emit).toHaveBeenCalledWith('notify', expect.objectContaining({ type: 'error' }))
    })
  })

  describe('removeImage and clearImages', () => {
    it('removes image at index', () => {
      modal.selectedImages.value = [{ file: 'a' }, { file: 'b' }]
      modal.imagePreviews.value = ['preview-a', 'preview-b']

      modal.removeImage(0)

      expect(modal.selectedImages.value).toHaveLength(1)
      expect(modal.selectedImages.value[0].file).toBe('b')
    })

    it('clears images by splicing all indices', () => {
      modal.selectedImages.value = [{ file: 'a' }, { file: 'b' }]
      modal.imagePreviews.value = ['prev-a', 'prev-b']

      modal.removeImage(0)
      modal.removeImage(0)

      expect(modal.selectedImages.value).toEqual([])
      expect(modal.imagePreviews.value).toEqual([])
    })
  })

  describe('deleteDoc', () => {
    beforeEach(() => {
      vi.stubGlobal('confirm', vi.fn(() => true))
    })

    it('sends DELETE request and refetches docs', async () => {
      axios.delete.mockResolvedValue({ status: 200 })
      axios.get.mockResolvedValue({ data: { data: [] } })

      await modal.deleteDoc(42)

      expect(axios.delete).toHaveBeenCalledWith(
        expect.stringContaining('/api/documentation/42'),
        expect.objectContaining({
          headers: { Authorization: 'Bearer test-token' }
        })
      )
      expect(axios.get).toHaveBeenCalled()
    })

    it('does nothing when confirm is cancelled', async () => {
      vi.stubGlobal('confirm', vi.fn(() => false))

      await modal.deleteDoc(42)

      expect(axios.delete).not.toHaveBeenCalled()
    })
  })
})
