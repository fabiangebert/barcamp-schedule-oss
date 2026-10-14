<template>
  <div
    :class="{ 'is-logged-in': !!user, 'is-editing': isEditing }"
    class="font-sans text-gray-mid bg-surface min-h-screen"
  >
    <Navbar
      v-model:email="email"
      :realtime="currentSchedule !== null"
      :is-connected="isConnected"
      :loading="loadingState.isLoading"
      :user="user"
      :user-name="userName"
      :is-admin="isAdmin"
      :show-user-management="showUserManagement"
      @go-home="goHome"
      @login="sendMagicLink"
      @logout="logout"
      @toggle-admin="toggleAdminView"
      @edit-name="showNameModal = true"
    />

    <div
      :class="{
        'container mx-auto max-w-6xl': !currentSchedule,
        'w-full': currentSchedule
      }"
      class="px-4 md:px-8"
    >
      <main class="relative pb-6">
        <LoginView
          v-if="!user"
          v-model:email="email"
          v-model:honeypot="honeypot"
          :alert-message="loginAlertMessage"
          @login="sendMagicLink"
        />
        <div v-if="currentSchedule">
          <ScheduleHeader
            v-model:schedule="currentSchedule"
            :is-user-or-admin="isUser || isAdmin"
            :can-edit="canEdit"
            :is-editing="isEditing"
            @update:schedule="saveSchedule"
            @save="saveSchedule"
            @open-feedback="openEventFeedback"
          />
          <template v-if="showDocumentationOverview && canSeeDocsOverview">
            <DocumentationOverview
              :schedule="currentSchedule"
              :documentation-counts="documentationCounts"
              :is-admin="isAdmin"
              :is-user="isUser"
              @export="exportDocumentation"
              @export-feedback-csv="exportFeedbackCsv"
              @open-session="openSessionDoc"
              @toggle-docs="toggleDocumentationOverview"
            />
          </template>
          <template v-else>
            <ScheduleGrid
              v-model:schedule="currentSchedule"
              :is-editing="canEdit && isEditing"
              :documentation-counts="documentationCounts"
              :total-doc-count="totalDocumentationCount"
              :can-edit="canEdit"
              @save="saveSchedule"
              @open-documentation="openSessionDoc"
              @open-feedback="openEventFeedback"
              @toggle-docs="toggleDocumentationOverview"
              @toggle-edit="toggleEditMode"
            />
          </template>

          <!-- Documentation Modal -->
          <DocumentationModal
            v-if="selectedSessionForDoc"
            :session="selectedSessionForDoc"
            :schedule-id="currentSchedule.hashed_id"
            :user-name="userName"
            :is-guest="isGuest"
            :guest-id="guestId"
            :user-id="user?.id"
            :token="token"
            :documentation-counts="documentationCounts"
            @close="closeSessionDoc"
            @notify="({ title, message, type }) => showNotification(title, message, type)"
          />

          <!-- Event Feedback Modal -->
          <EventFeedbackModal
            v-if="showEventFeedback"
            :schedule-id="currentSchedule.hashed_id"
            :schedule-name="currentSchedule.name"
            :user-name="userName"
            :token="token"
            :is-guest="isGuest"
            :guest-id="guestId"
            :user-id="user?.id"
            :documentation-counts="documentationCounts"
            :feedback-questions="currentSchedule.data?.feedback_questions ?? []"
            @close="showEventFeedback = false"
            @notify="({ title, message, type }) => showNotification(title, message, type)"
          />
        </div>
        <AdminView
          v-else-if="showUserManagement && isAdmin"
          :show-notification="showNotification"
        />
        <ScheduleList
          v-else-if="canSeeList"
          :schedules="sortedSchedules"
          :user="user"
          :is-admin="isAdmin"
          :sort-column="sortColumn"
          :sort-order="sortOrder"
          @show-new-modal="showNewScheduleModal = true"
          @toggle-sort="toggleSort"
          @request-delete="requestDeleteSchedule"
          @share="openShareModal"
          @select-schedule="selectSchedule"
        />
        <div v-else-if="user" class="py-20 text-center">
          <p class="text-gray-mid">{{ t('auth.noPermission') }}</p>
          <button class="mt-4 text-primary font-bold" @click="logout">
            {{ t('navbar.logout') }}
          </button>
        </div>
      </main>

      <!-- Share Modal -->
      <ShareModal
        v-if="showShareModal"
        :visible="showShareModal"
        :schedule-name="scheduleToShare"
        :schedule-id="scheduleIdToShare"
        :token="token"
        :is-admin="isAdmin"
        :is-user="isUser"
        :show-notification="showNotification"
        @close="showShareModal = false"
      />

      <!-- New Schedule Modal -->
      <NewScheduleModal
        v-model="newScheduleName"
        :visible="showNewScheduleModal"
        @close="showNewScheduleModal = false"
        @create="createSchedule"
      />
      <ConfirmationModal
        :visible="deleteModalVisible"
        :title="t('modals.deleteScheduleTitle')"
        :message="
          t('modals.deleteScheduleMessage', { name: scheduleNameToDelete })
        "
        :confirm-text="t('common.delete')"
        :cancel-text="t('common.cancel')"
        @confirm="confirmDeleteSchedule"
        @cancel="cancelDeleteSchedule"
      />
      <NameModal
        :visible="showNameModal"
        :initial-name="userName || ''"
        :is-name-customized="isNameCustomized"
        :show-cancel="isNameCustomized"
        @confirm="updateName"
        @cancel="showNameModal = false"
      />
      <ConfirmationModal
        :visible="showLogoutConfirm"
        :title="t('schedule.logoutConfirmTitle')"
        :message="t('schedule.logoutConfirmMessage')"
        :confirm-text="t('navbar.logout')"
        :cancel-text="t('common.cancel')"
        @confirm="confirmLogout"
        @cancel="showLogoutConfirm = false"
      />
      <NotificationModal
        :visible="notification.visible"
        :title="notification.title"
        :message="notification.message"
        :type="notification.type"
        :confirm-text="notification.confirmText"
        :cancel-text="notification.cancelText"
        :closable="notification.closable"
        @close="notification.onCancel"
        @confirm="notification.onConfirm"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'
import { useI18n } from 'vue-i18n'
import { API_BASE } from './constants'
import { loadingState } from './utils/loadingState'
import { useScheduleHandling } from './composables/useScheduleHandling'
import { showApiError } from './utils/notificationHelpers'
import { useAuth } from './composables/useAuth'
import { useNotifications } from './composables/useNotifications'
import { useSchedules } from './composables/useSchedules'
import { useSSE } from './composables/useSSE'
import LoginView from './views/LoginView.vue'
import ScheduleHeader from './components/schedule/ScheduleHeader.vue'
import ScheduleGrid from './components/schedule/ScheduleGrid.vue'
import DocumentationModal from './components/documentation/DocumentationModal.vue'
import DocumentationOverview from './components/documentation/DocumentationOverview.vue'
import AdminView from './views/AdminView.vue'
import ConfirmationModal from './components/modals/ConfirmationModal.vue'
import NotificationModal from './components/modals/NotificationModal.vue'
import Navbar from './components/layout/Navbar.vue'
import ScheduleList from './components/schedule/ScheduleList.vue'
import NewScheduleModal from './components/schedule/NewScheduleModal.vue'
import ShareModal from './components/share/ShareModal.vue'
import NameModal from './components/modals/NameModal.vue'
import EventFeedbackModal from './components/modals/EventFeedbackModal.vue'
const { t } = useI18n()

const { notification, showNotification, closeNotification } = useNotifications()

const {
  user,
  userName,
  token,
  isAdmin,
  isUser,
  isGuest,
  isNameCustomized,
  canEdit,
  canSeeList,
  canSeeDocsOverview,
  logout: baseLogout,
  updateName: baseUpdateName
} = useAuth(showNotification)

const guestId = ref(localStorage.getItem('guestId') || '')
if (!guestId.value) {
  guestId.value =
    Math.random().toString(36).substring(2, 15) +
    Math.random().toString(36).substring(2, 15)
  localStorage.setItem('guestId', guestId.value)
}

const email = ref('')
const honeypot = ref('')
const newScheduleName = ref('')
const isEditing = ref(false)
const showUserManagement = ref(false)
const showDocumentationOverview = ref(false)
const showEventFeedback = ref(false)
const showNewScheduleModal = ref(false)
const showShareModal = ref(false)
const showNameModal = ref(false)
const showLogoutConfirm = ref(false)
const scheduleToShare = ref(null)
const scheduleIdToShare = ref(null)
const selectedSessionForDoc = ref(null)
const loginAlertMessage = ref('')

const totalDocumentationCount = computed(() => {
  return documentationCounts.value
    .filter((doc) => doc.timeslot !== 'event' && doc.room !== 'event')
    .reduce((total, d) => total + (d.count || 0), 0)
})

const logout = () => {
  if (isGuest.value) {
    showLogoutConfirm.value = true
    return
  }
  confirmLogout()
}

const confirmLogout = () => {
  showLogoutConfirm.value = false
  baseLogout([
    () => (isEditing.value = false),
    () => (showUserManagement.value = false),
    () => (showDocumentationOverview.value = false),
    () => (showNameModal.value = false),
    () => (currentSchedule.value = null)
  ])
}

const currentSchedule = ref(null)
const lastSavingData = ref(null)
const documentationCounts = ref([])
const isConnected = ref(false)

const { fetchSchedule: baseFetchSchedule, saveSchedule: baseSaveSchedule } =
  useScheduleHandling(token, API_BASE, axios, showNotification, confirmLogout, {
    currentSchedule,
    lastSavingData,
    documentationCounts,
    isConnected,
    closeNotification,
    isGuest
  })

const refreshSchedule = async () => {
  if (currentSchedule.value?.hashed_id) {
    await fetchSchedule(currentSchedule.value.hashed_id)
  }
}

useSSE(
  token,
  API_BASE,
  axios,
  currentSchedule,
  documentationCounts,
  lastSavingData,
  isEditing,
  showNotification,
  notification,
  isConnected,
  refreshSchedule
)

const {
  sortColumn,
  sortOrder,
  deleteModalVisible,
  scheduleNameToDelete,
  fetchSchedules: baseFetchSchedules,
  sortedSchedules,
  toggleSort,
  requestDeleteSchedule,
  cancelDeleteSchedule,
  confirmDeleteSchedule
} = useSchedules(token, API_BASE, axios, showNotification, isConnected)

const fetchSchedules = () => baseFetchSchedules()

const updateName = async (newName) => {
  const success = await baseUpdateName(axios, API_BASE, newName)
  if (success) {
    showNameModal.value = false
    showNotification(
      t('common.success'),
      t('auth.nameUpdated'),
      'success'
    )

    // If we were trying to open a session or event feedback, do it now
    if (
      selectedSessionForDoc.value === null &&
      showEventFeedback.value === false
    ) {
      // Re-trigger the logic that was interrupted
      // This is a bit tricky since we don't know which one was clicked.
      // But usually if they are null/false, they were just about to be set.
      // Actually, if we just updated the name, the user can click again.
      // Or we could have saved what they wanted to open.
    }
  }
}

const goHome = () => {
  currentSchedule.value = null
  showDocumentationOverview.value = false
  showUserManagement.value = false
  showNewScheduleModal.value = false
  window.history.pushState({}, '', window.location.pathname)
  if (isGuest.value && localStorage.getItem('redirect_schedule')) {
    fetchSchedule(localStorage.getItem('redirect_schedule'))
  } else if (!isGuest.value) {
    fetchSchedules()
  } else {
    // No action needed
  }
}

const saveStatus = ref('')

const toggleAdminView = () => {
  showUserManagement.value = !showUserManagement.value
  showDocumentationOverview.value = false
  currentSchedule.value = null
  if (!showUserManagement.value) {
    fetchSchedules()
  }
}

const fetchSchedule = async (id) => {
  if (!token.value) {
    return
  }
  await baseFetchSchedule(id, {
    onSuccess: () => {
      // Check URL for mode
      const urlParams = new URLSearchParams(window.location.hash.substring(1))
      const mode = urlParams.get('mode')
      if (mode === 'documentation') {
        showDocumentationOverview.value = true
      } else {
        isEditing.value = user.value && mode === 'edit'
        showDocumentationOverview.value = false
      }
    },
    onNotFound: () => {
      const urlParams = new URLSearchParams(window.location.hash.substring(1))
      urlParams.delete('schedule')
      urlParams.delete('mode')
      window.history.replaceState(
        {},
        '',
        `${window.location.pathname}${urlParams.toString() ? '#' + urlParams.toString() : ''}`
      )
      // If the guest's stored schedule no longer exists, clear it so
      // goHome() does not re-fetch the deleted schedule in a loop.
      if (id && localStorage.getItem('redirect_schedule') === id) {
        localStorage.removeItem('redirect_schedule')
      }
      goHome()
    }
  })
}

const selectSchedule = (s) => {
  const url = new URL(window.location.href)
  const hashParams = new URLSearchParams(url.hash.substring(1))
  hashParams.set('schedule', s.hashed_id)
  url.hash = hashParams.toString()
  url.search = '' // Clear query parameters
  window.history.pushState({}, '', url.toString())
  fetchSchedule(s.hashed_id)
}

const toggleEditMode = () => {
  isEditing.value = !isEditing.value
  const urlParams = new URLSearchParams(window.location.hash.substring(1))
  if (isEditing.value) {
    urlParams.set('mode', 'edit')
  } else {
    urlParams.delete('mode')
  }
  const search = urlParams.toString()
  window.history.pushState(
    {},
    '',
    `${window.location.pathname}${search ? '#' + search : ''}`
  )
}

const createSchedule = async () => {
  if (!newScheduleName.value) return
  const name = newScheduleName.value
  const data = {
    rooms: [{name: 'Plenary', size: 'L'}],
    timeslots: [{name: '10:00'}],
    sessions: [],
    topics: [{name:'Topic 1', colorIndex: 1}, {name:'Topic 2', colorIndex: 2}]
  }
  try {
    await axios.post(
      `${API_BASE}/schedules`,
      { name, data },
      {
        headers: { Authorization: `Bearer ${token.value}` }
      }
    )
    newScheduleName.value = ''
    showNewScheduleModal.value = false
    await fetchSchedules()
  } catch (err) {
    showApiError(showNotification, t('notifications.error'), t('auth.failedToCreateSchedule'), err)
  }
}

const sendMagicLink = async () => {
  if (honeypot.value) {
    // Honeypot bot trap triggered: simulate success silently
    showNotification(
      t('auth.magicLinkSent'),
      t('auth.magicLinkSentMessage'),
      'success'
    )
    honeypot.value = ''
    return
  }

  try {
    await axios.post(`${API_BASE}/auth/magic-link`, { email: email.value })
    showNotification(
      t('auth.magicLinkSent'),
      t('auth.magicLinkSentMessage'),
      'success'
    )
  } catch (err) {
    let errorMessage = err.response?.data?.error || err.message
    if (err.response?.status === 429) {
      errorMessage = t('auth.tooManyRequests')
    }
    showNotification(
      t('common.error'),
      t('auth.failedToSendMagicLink') +
        ': ' +
        errorMessage,
      'error'
    )
  } finally {
    honeypot.value = ''
  }
}

const login = async (magicToken, shareToken) => {
  const params = {}
  if (magicToken) params.token = magicToken
  if (shareToken) params.share_token = shareToken

  const res = await axios.get(`${API_BASE}/auth/login`, { params })
  token.value = res.data.token
  localStorage.setItem('token', token.value)
  user.value = res.data.user
  localStorage.setItem('user', JSON.stringify(user.value))
  userName.value = res.data.user.name || null
  if (userName.value) {
    localStorage.setItem('userName', userName.value)
  } else {
    localStorage.removeItem('userName')
  }
  isGuest.value = res.data.user.role === 'guest'
  localStorage.setItem('role', res.data.user.role)
  // Ensure we update these when they change during login
  isAdmin.value = res.data.user.role === 'admin'
  isUser.value = res.data.user.role === 'user'

  if (isGuest.value && !isNameCustomized.value) {
    showNameModal.value = true
  }

  if (res.data.redirect_schedule) {
    localStorage.setItem('redirect_schedule', res.data.redirect_schedule)
  } else if (!isGuest.value) {
    localStorage.removeItem('redirect_schedule')
  }
  return res.data
}

const toggleDocumentationOverview = () => {
  showDocumentationOverview.value = !showDocumentationOverview.value
  const urlParams = new URLSearchParams(window.location.hash.substring(1))
  if (showDocumentationOverview.value) {
    urlParams.set('schedule', currentSchedule.value.hashed_id)
    urlParams.set('mode', 'documentation')
    urlParams.delete('documentation')
  } else {
    urlParams.delete('mode')
    urlParams.set('schedule', currentSchedule.value.hashed_id)
    urlParams.delete('documentation')
  }
  const search = urlParams.toString()
  window.history.pushState(
    {},
    '',
    `${window.location.pathname}${search ? '#' + search : ''}`
  )
}

// Export endpoints answer with responseType 'blob', so an error payload arrives
// as a Blob rather than parsed JSON. Read it back so the server's message can
// be surfaced instead of a generic failure notice.
const readExportError = async (error) => {
  const data = error?.response?.data
  try {
    const parsed =
      data instanceof Blob ? JSON.parse(await data.text()) : data
    return typeof parsed?.error === 'string' ? parsed.error : null
  } catch {
    return null
  }
}

const downloadBlob = (data, filename) => {
  const url = window.URL.createObjectURL(new Blob([data]))
  const link = document.createElement('a')
  link.href = url
  link.setAttribute('download', filename)
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(url)
}

// A schedule without feedback exports a headers-only CSV. Report that instead of
// handing over a file that looks like a broken export.
const isHeadersOnlyCsv = async (data) => {
  if (!(data instanceof Blob)) return false
  const text = await data.text()
  return text.split('\n').filter((line) => line.trim() !== '').length <= 1
}

const exportDocumentation = async () => {
  if (!currentSchedule.value) return

  try {
    const response = await axios.get(
      `${API_BASE}/schedules/${currentSchedule.value.hashed_id}/export`,
      {
        headers: { Authorization: `Bearer ${token.value}` },
        responseType: 'blob'
      }
    )

    downloadBlob(response.data, `documentation_${currentSchedule.value.hashed_id}.zip`)
  } catch (error) {
    if (error?.response?.status === 404) {
      showNotification(t('documentation.noData'), t('documentation.noDataMessage'), 'info')
      return
    }
    const serverError = await readExportError(error)
    showNotification(
      t('documentation.exportFailed'),
      serverError
        ? `${t('documentation.exportFailedMessage')}: ${serverError}`
        : t('documentation.exportFailedMessage'),
      'error'
    )
  }
}

const exportFeedbackCsv = async () => {
  if (!currentSchedule.value) return

  try {
    const response = await axios.get(
      `${API_BASE}/schedules/${currentSchedule.value.hashed_id}/feedback/export/csv`,
      {
        headers: { Authorization: `Bearer ${token.value}` },
        responseType: 'blob'
      }
    )

    if (await isHeadersOnlyCsv(response.data)) {
      showNotification(t('feedback.noData'), t('feedback.noDataMessage'), 'info')
      return
    }

    downloadBlob(response.data, `feedback_${currentSchedule.value.hashed_id}.csv`)
  } catch (error) {
    if (error?.response?.status === 404) {
      showNotification(t('feedback.noData'), t('feedback.noDataMessage'), 'info')
      return
    }
    const serverError = await readExportError(error)
    showNotification(
      t('feedback.exportFailed'),
      serverError
        ? `${t('feedback.exportFailedMessage')}: ${serverError}`
        : t('feedback.exportFailedMessage'),
      'error'
    )
  }
}

const openSessionDoc = (session) => {
  if (!userName.value) {
    showNameModal.value = true
    return
  }
  selectedSessionForDoc.value = session
}

const closeSessionDoc = () => {
  selectedSessionForDoc.value = null
}

const openEventFeedback = () => {
  if (!userName.value) {
    showNameModal.value = true
    return
  }
  showEventFeedback.value = true
}

const saveSchedule = async () => {
  await baseSaveSchedule(saveStatus)
}

const openShareModal = (s) => {
  scheduleToShare.value = s.name
  scheduleIdToShare.value = s.hashed_id
  showShareModal.value = true
}

const handlePopState = () => {
  const urlParams = new URLSearchParams(window.location.hash.substring(1))
  const scheduleId = urlParams.get('schedule')
  const mode = urlParams.get('mode')
  const documentationName = urlParams.get('documentation')

  if (documentationName || (scheduleId && mode === 'documentation')) {
    showDocumentationOverview.value = true
    fetchSchedule(documentationName || scheduleId)
  } else if (scheduleId) {
    showDocumentationOverview.value = false
    fetchSchedule(scheduleId)
  } else {
    currentSchedule.value = null
    showDocumentationOverview.value = false
    showUserManagement.value = false
    if (!isGuest.value) {
      fetchSchedules()
    }
  }
}

onMounted(() => {
  window.addEventListener('popstate', handlePopState)
  const urlParams = new URLSearchParams(window.location.hash.substring(1))
  const magicToken = urlParams.get('token')
  const shareToken = urlParams.get('share_token')
  const scheduleId = urlParams.get('schedule')
  const documentationName = urlParams.get('documentation')
  const mode = urlParams.get('mode')
  const reason = urlParams.get('reason')

  if (reason === 'expired') {
    loginAlertMessage.value = t('auth.sessionExpired')
    window.history.replaceState({}, document.title, window.location.pathname)
  }

  if (magicToken || shareToken) {
    login(magicToken, shareToken)
      .then((data) => {
        window.history.replaceState(
          {},
          document.title,
          window.location.pathname
        )
        const redirectSchedule = data.redirect_schedule || scheduleId

        if (!data.user.name) {
          showNameModal.value = true
        }

        if (
          documentationName ||
          (redirectSchedule && mode === 'documentation')
        ) {
          showDocumentationOverview.value = true
          fetchSchedule(documentationName || redirectSchedule)
        } else if (redirectSchedule) {
          fetchSchedule(redirectSchedule)
        } else {
          fetchSchedules()
        }
      })
      .catch((err) => {
        console.error('Login failed', err)
        window.history.replaceState(
          {},
          document.title,
          window.location.pathname
        )
        const errorMsg = shareToken
          ? t('auth.invalidShareLink')
          : t('auth.invalidMagicLink')
        showNotification(t('auth.loginFailed'), errorMsg, 'error')
      })
  } else if (user.value) {
    if (!userName.value || (isGuest.value && !isNameCustomized.value)) {
      showNameModal.value = true
    }
    if (documentationName || (scheduleId && mode === 'documentation')) {
      showDocumentationOverview.value = true
      fetchSchedule(documentationName || scheduleId)
    } else if (scheduleId) {
      fetchSchedule(scheduleId)
    } else if (isGuest.value && localStorage.getItem('redirect_schedule')) {
      fetchSchedule(localStorage.getItem('redirect_schedule'))
    } else if (!isGuest.value) {
      fetchSchedules()
    }
  } else {
    // No action needed
  }
})
</script>

<style></style>
