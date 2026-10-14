/**
 * Server timestamps are stored as timezone-naive UTC strings
 * (PHP runs with date.timezone=UTC, SQLite CURRENT_TIMESTAMP is UTC).
 * JavaScript parses naive strings as local time, so they must be
 * explicitly interpreted as UTC before formatting or comparing.
 */

const NAIVE_DATETIME = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?$/
const NAIVE_DATE = /^\d{4}-\d{2}-\d{2}$/

export function parseStoredDate(dateStr) {
  if (!dateStr) return null
  if (NAIVE_DATETIME.test(dateStr)) {
    return new Date(dateStr.replace(' ', 'T') + 'Z')
  }
  if (NAIVE_DATE.test(dateStr)) {
    return new Date(dateStr + 'T00:00:00Z')
  }
  return new Date(dateStr)
}

export function toUtcDbString(date) {
  return date.toISOString().slice(0, 19).replace('T', ' ')
}
