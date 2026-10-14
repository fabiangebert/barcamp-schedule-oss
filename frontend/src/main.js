import { createApp } from 'vue'
import { createPinia } from 'pinia'
import axios from 'axios'
import App from './App.vue'
import './assets/main.css'
import i18n from './i18n'
import { startLoading, stopLoading } from './utils/loadingState'
import { useDisplayMode } from './composables/useDisplayMode'

const app = createApp(App)
const pinia = createPinia()
app.use(pinia)

axios.interceptors.request.use(
  (config) => {
    startLoading()
    return config
  },
  (error) => {
    stopLoading()
    return Promise.reject(error)
  }
)

axios.interceptors.response.use(
  (response) => {
    stopLoading()
    const newToken = response.headers['x-new-token']
    if (newToken) {
      localStorage.setItem('token', newToken)
    }
    return response
  },
  (error) => {
    stopLoading()
    console.error('Axios Error:', error)
    if (error.response?.status === 401) {
      if (window.isRedirecting) {
        return Promise.reject(error)
      }
      window.isRedirecting = true

      localStorage.removeItem('user')
      localStorage.removeItem('userName')
      localStorage.removeItem('token')
      localStorage.removeItem('role')
      localStorage.removeItem('redirect_schedule')
      const url = new URL(window.location.href)
      const params = new URLSearchParams(url.hash.substring(1))
      params.set('reason', 'expired')
      url.hash = params.toString()
      url.search = '' // Clear query parameters
      window.location.href = url.toString()
      // Force page reload since hash changes do not trigger a reload automatically
      window.location.reload()
    }
    return Promise.reject(error)
  }
)

app.use(i18n)

// Applied before mount so the projector never renders a light flash first.
const displayMode = useDisplayMode()
displayMode.init()
displayMode.applyFromLocation(window.location.hash)

app.mount('#app')
