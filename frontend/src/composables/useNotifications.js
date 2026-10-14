import { ref } from 'vue'

export function useNotifications() {
  const notification = ref({
    visible: false,
    title: '',
    message: '',
    type: 'success',
    confirmText: 'OK',
    cancelText: 'Cancel',
    closable: true,
    onConfirm: () => {},
    onCancel: () => {}
  })

  const defaultOnConfirm = () => { notification.value.visible = false }
  const defaultOnCancel = () => { notification.value.visible = false }

  function showNotification(title, message, type = 'success', options = {}) {
    notification.value = {
      visible: true,
      title,
      message,
      type,
      confirmText: options.confirmText || 'OK',
      cancelText: options.cancelText || 'Cancel',
      closable: options.closable !== undefined ? options.closable : true,
      onConfirm: options.onConfirm || defaultOnConfirm,
      onCancel: options.onCancel || defaultOnCancel
    }
  }

  const closeNotification = () => {
    notification.value.visible = false
  }

  return {
    notification,
    showNotification,
    closeNotification
  }
}
