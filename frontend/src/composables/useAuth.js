import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { showApiError } from '../utils/notificationHelpers'

const getUserFromStorage = () => {
  const storedUser = localStorage.getItem('user')
  if (!storedUser) return null
  try {
    return JSON.parse(storedUser)
  } catch {
    return storedUser // Fallback for old simple sub strings
  }
}

export function useAuth(showNotification) {
  const { t } = useI18n()
  const user = ref(getUserFromStorage())
  const userName = ref(localStorage.getItem('userName'))
  const token = ref(localStorage.getItem('token'))
  const isAdmin = ref(localStorage.getItem('role') === 'admin')
  const isUser = ref(localStorage.getItem('role') === 'user')
  const isGuest = ref(localStorage.getItem('role') === 'guest')
  const isNameCustomized = ref(localStorage.getItem('isNameCustomized') === 'true')

  const canEdit = computed(() => isAdmin.value || isUser.value)
  const canSeeList = computed(() => isAdmin.value || isUser.value)
  const canSeeDocsOverview = computed(() => isAdmin.value || isUser.value || isGuest.value)

  const logout = (callbacks = []) => {
    user.value = null
    userName.value = null
    token.value = null
    isAdmin.value = false
    isUser.value = false
    isGuest.value = false
    isNameCustomized.value = false
    
    localStorage.removeItem('user')
    localStorage.removeItem('userName')
    localStorage.removeItem('token')
    localStorage.removeItem('role')
    localStorage.removeItem('isNameCustomized')
    localStorage.removeItem('redirect_schedule')
    
    callbacks.forEach(cb => cb())
  }

  const updateName = async (axios, API_BASE, newName) => {
    try {
      const res = await axios.post(`${API_BASE}/user/name`, { name: newName }, {
        headers: { Authorization: `Bearer ${token.value}` }
      })
      if (res.data.token) {
        token.value = res.data.token
        localStorage.setItem('token', token.value)
      }
      userName.value = newName
      localStorage.setItem('userName', newName)
      isNameCustomized.value = true
      localStorage.setItem('isNameCustomized', 'true')
      return true
    } catch (err) {
      showApiError(showNotification, t('notifications.error'), t('auth.failedToUpdateName'), err)
      return false
    }
  }

  return {
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
    logout,
    updateName
  }
}
