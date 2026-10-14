import { ref, computed, nextTick, watch } from 'vue'
import axios from 'axios'
import { API_BASE, MAX_UPLOAD_SIZE } from '../constants'

/**
 * Shared state and logic for documentation modals (session and event modes).
 *
 * @param {Object} props - component props (scheduleId, token, isGuest, guestId, userName, documentationCounts)
 * @param {Function} emit - component emit function
 * @param {Function} getEndpointParts - returns { timeslot, room } for the API endpoint
 */
export function useDocumentationModal(props, emit, getEndpointParts, t) {
  const docs = ref([])
  const loading = ref(true)
  const submitting = ref(false)
  const newComment = ref('')
  const selectedImages = ref([])
  const imagePreviews = ref([])
  const zoomedImage = ref(null)
  const scrollContainer = ref(null)

  const isPublicContribution = computed(() => {
    if (!props.isGuest) return true
    return getEndpointParts().timeslot !== 'event'
  })

  const scrollToBottom = () => {
    nextTick(() => {
      if (scrollContainer.value) {
        scrollContainer.value.scrollTop = scrollContainer.value.scrollHeight
      }
    })
  }

  const fetchDocs = async () => {
    try {
      const { timeslot, room } = getEndpointParts()
      const response = await axios.get(
        `${API_BASE}/schedules/${props.scheduleId}/sessions/${encodeURIComponent(timeslot)}/${encodeURIComponent(room)}/documentation`,
        {
          headers: {
            ...(props.token ? { Authorization: `Bearer ${props.token}` } : {}),
            ...(props.isGuest ? { 'X-Guest-Id': props.guestId } : {})
          }
        }
      )
      docs.value = response.data.data || []
      scrollToBottom()
    } catch (e) {
      console.error('Failed to fetch documentation', e)
    } finally {
      loading.value = false
    }
  }

  const handleImageUpload = (event) => {
    const files = Array.from(event.target.files)
    const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'application/pdf']
    files.forEach((file) => {
      if (!allowedTypes.includes(file.type)) {
        emit('notify', { title: t('common.error'), message: t('documentation.upload.unsupportedType', { name: file.name }), type: 'error' })
        return
      }
      if (MAX_UPLOAD_SIZE > 0 && file.size > MAX_UPLOAD_SIZE) {
        const sizeMB = (MAX_UPLOAD_SIZE / (1024 * 1024)).toFixed(1)
        emit('notify', { title: t('common.error'), message: t('documentation.upload.fileTooLarge', { name: file.name, sizeMB }), type: 'error' })
        return
      }
      const fileObj = { file, type: file.type, uploading: false, progress: 0, path: null, error: null }
      selectedImages.value.push(fileObj)
      if (file.type.startsWith('image/')) {
        const reader = new FileReader()
        reader.onload = (e) => {
          const currentIdx = selectedImages.value.findIndex(img => img.file === file)
          if (currentIdx !== -1) imagePreviews.value[currentIdx] = e.target.result
        }
        reader.readAsDataURL(file)
        imagePreviews.value.push(null)
      } else {
        imagePreviews.value.push(null)
      }
    })
  }

  const removeImage = (index) => {
    selectedImages.value.splice(index, 1)
    imagePreviews.value.splice(index, 1)
  }

  const clearImages = () => {
    selectedImages.value = []
    imagePreviews.value = []
  }

  const uploadFile = (fileObj) => {
    return new Promise((resolve, reject) => {
      const formData = new FormData()
      formData.append('file', fileObj.file)
      const xhr = new XMLHttpRequest()
      xhr.open('POST', `${API_BASE}/schedules/${props.scheduleId}/upload`)
      if (props.token) xhr.setRequestHeader('Authorization', `Bearer ${props.token}`)

      xhr.upload.onprogress = (event) => {
        if (event.lengthComputable) {
          fileObj.progress = Math.round((event.loaded / event.total) * 100)
        }
      }
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) {
          const response = JSON.parse(xhr.responseText)
          fileObj.path = response.path
          fileObj.uploading = false
          fileObj.progress = 100
          resolve(response.path)
        } else {
          let errorMsg = t('documentation.upload.uploadFailed')
          try { errorMsg = JSON.parse(xhr.responseText).error || errorMsg } catch { /* ignore */ }
          fileObj.error = errorMsg
          fileObj.uploading = false
          reject(new Error(errorMsg))
        }
      }
      xhr.onerror = () => {
        const errorMsg = fileObj.file.size > 0 ? t('documentation.upload.networkErrorLimit') : t('documentation.upload.networkErrorGeneric')
        fileObj.error = errorMsg
        fileObj.uploading = false
        reject(new Error(errorMsg))
      }
      fileObj.uploading = true
      fileObj.error = null
      xhr.send(formData)
    })
  }

  const submitDoc = async (additionalPayload = {}) => {
    if (!props.userName.trim()) {
      emit('notify', { title: t('common.error'), message: t('documentation.upload.pleaseEnterName'), type: 'error' })
      return
    }

    submitting.value = true
    const uploadedPaths = []
    try {
      for (const fileObj of selectedImages.value) {
        if (fileObj.path) { uploadedPaths.push(fileObj.path); continue }
        uploadedPaths.push(await uploadFile(fileObj))
      }
    } catch (e) {
      console.error('File upload failed', e)
      emit('notify', { title: t('common.error'), message: e.message || t('documentation.upload.fileUploadFailed'), type: 'error' })
      submitting.value = false
      return
    }

    const { timeslot, room } = getEndpointParts()
    const payload = {
      content: newComment.value,
      images: uploadedPaths,
      guest_name: props.isGuest ? props.userName.trim() || undefined : undefined,
      guest_id: props.isGuest ? props.guestId : undefined,
      is_public: isPublicContribution.value,
      ...additionalPayload
    }

    try {
      const response = await axios.post(
        `${API_BASE}/schedules/${props.scheduleId}/sessions/${encodeURIComponent(timeslot)}/${encodeURIComponent(room)}/documentation`,
        payload,
        {
          headers: {
            'Content-Type': 'application/json',
            ...(props.token ? { Authorization: `Bearer ${props.token}` } : {})
          }
        }
      )
      if (response.status >= 200 && response.status < 300) {
        newComment.value = ''
        clearImages()
        await fetchDocs()
      } else {
        emit('notify', { title: t('common.error'), message: t('documentation.saveError'), type: 'error' })
      }
    } catch (e) {
      console.error('Failed to submit documentation', e)
      emit('notify', { title: t('common.error'), message: t('documentation.submitFailed'), type: 'error' })
    } finally {
      submitting.value = false
    }
  }

  const deleteDoc = async (id) => {
    if (!confirm(t('documentation.deleteConfirm'))) return
    try {
      const response = await axios.delete(`${API_BASE}/documentation/${id}`, {
        headers: { Authorization: `Bearer ${props.token}` }
      })
      if (response.status >= 200 && response.status < 300) {
        await fetchDocs()
      } else {
        emit('notify', { title: t('common.error'), message: response.data.error || t('documentation.deleteError'), type: 'error' })
      }
    } catch (e) {
      console.error('Failed to delete documentation', e)
      emit('notify', { title: t('common.error'), message: t('documentation.deleteFailed'), type: 'error' })
    }
  }

  watch(() => props.documentationCounts, () => {
    fetchDocs()
  })

  return {
    docs,
    loading,
    submitting,
    newComment,
    selectedImages,
    imagePreviews,
    zoomedImage,
    scrollContainer,
    isPublicContribution,
    fetchDocs,
    handleImageUpload,
    removeImage,
    submitDoc,
    deleteDoc
  }
}
