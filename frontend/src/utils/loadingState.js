import { reactive } from 'vue'

export const loadingState = reactive({
  activeRequests: 0,
  isLoading: false
})

export const startLoading = () => {
  loadingState.activeRequests++
  loadingState.isLoading = true
}

export const stopLoading = () => {
  loadingState.activeRequests = Math.max(0, loadingState.activeRequests - 1)
  // Ensure we don't trigger a re-render during a sensitive lifecycle phase
  // using nextTick for the isLoading state change.
  if (loadingState.activeRequests === 0) {
    // Use setTimeout(0) to ensure this happens after the current task and any
    // immediate microtasks/renders, which is more robust than nextTick in some browsers.
    setTimeout(() => {
      if (loadingState.activeRequests === 0) {
        loadingState.isLoading = false
      }
    }, 0)
  } else {
    loadingState.isLoading = true
  }
}
