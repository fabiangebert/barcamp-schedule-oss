import {TAB_STALENESS_THRESHOLD} from "../constants.js";

export function createStalenessDetector({
                                          onStale,
                                          thresholdMs = TAB_STALENESS_THRESHOLD,
                                          documentObj = globalThis.document,
                                          windowObj = globalThis.window,
                                          nowFn = Date.now
                                        }) {
  // Initialize if already hidden to catch stale tabs on first load
  let lastHiddenTime = documentObj.visibilityState === 'hidden' ? nowFn() : null

  const checkStale = () => {
    const isVisible = documentObj.visibilityState === 'visible'

    if (!isVisible) {
      // Only update if it wasn't already hidden to keep the original start time
      if (!lastHiddenTime) lastHiddenTime = nowFn()
    } else {
      if (lastHiddenTime) {
        const hiddenDuration = nowFn() - lastHiddenTime
        if (hiddenDuration >= thresholdMs) {
          onStale(hiddenDuration) // Pass duration to the callback
        }
      }
      lastHiddenTime = null
    }
  }

  const start = () => {
    documentObj.addEventListener('visibilitychange', checkStale)
    windowObj.addEventListener('focus', checkStale) // Fallback for system wake
  }

  const stop = () => {
    documentObj.removeEventListener('visibilitychange', checkStale)
    windowObj.removeEventListener('focus', checkStale)
  }

  return { start, stop }
}