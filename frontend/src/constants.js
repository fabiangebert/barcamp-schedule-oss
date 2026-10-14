export const API_BASE = import.meta.env.VITE_API_BASE || import.meta.env.API_BASE || '/api'
export const MAX_UPLOAD_SIZE = parseInt(import.meta.env.VITE_MAX_UPLOAD_SIZE || '0') || parseInt(import.meta.env.MAX_UPLOAD_SIZE || '0') || 10485760
export const TAB_STALENESS_THRESHOLD = parseInt(import.meta.env.VITE_TAB_STALENESS_THRESHOLD || '0') || parseInt(import.meta.env.TAB_STALENESS_THRESHOLD || '0') || 60000
