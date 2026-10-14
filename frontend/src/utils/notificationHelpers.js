
/**
 * Shows a standard API error notification: "Error" title + translated message + error detail.
 * @param {Function} showNotification
 * @param {string} errorTitle - translated "Error" title
 * @param {string} translatedMessage - already translated human-readable action message
 * @param {Error} err - the caught error
 */
export function showApiError(showNotification, errorTitle, translatedMessage, err) {
  showNotification(
    errorTitle,
    translatedMessage + ': ' + (err.response?.data?.error || err.message),
    'error'
  )
}

/**
 * Shared logic for showing the version mismatch notification.
 * @param {Object} showNotification - The notification function
 * @param {string} title - translated title
 * @param {string} message - translated message
 * @param {string} confirmText - translated confirm text
 * @param {string} cancelText - translated cancel text
 * @param {Object} currentSchedule - Vue ref for the current schedule
 * @param {Object} lastSavingData - Vue ref for the last saved data
 * @param {Object} newSchedule - The new schedule data from the server
 * @param {Object} [notification] - (Optional) Vue ref for the notification to hide it on confirm
 */
export function showVersionMismatchNotification(showNotification, title, message, confirmText, cancelText, currentSchedule, lastSavingData, newSchedule, notification = null) {
  showNotification(title, message, 'info', {
    confirmText,
    cancelText,
    onConfirm: () => {
      if (newSchedule) {
        currentSchedule.value = newSchedule;
        lastSavingData.value = {
          data: JSON.parse(JSON.stringify(newSchedule.data)),
          name: newSchedule.name,
          date: newSchedule.date
        };
        if (notification && notification.value) {
          notification.value.visible = false;
        }
      }
    }
  });
}
