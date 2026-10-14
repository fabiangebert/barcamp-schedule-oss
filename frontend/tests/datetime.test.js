import { describe, it, expect } from 'vitest'
import { parseStoredDate, toUtcDbString } from '../src/utils/datetime'

describe('parseStoredDate', () => {
  it('interprets naive datetime strings as UTC', () => {
    const result = parseStoredDate('2026-09-25 15:00:00')
    expect(result.getTime()).toBe(Date.UTC(2026, 8, 25, 15, 0, 0))
  })

  it('handles ISO-style naive strings with T separator', () => {
    const result = parseStoredDate('2026-09-25T15:00:00')
    expect(result.getTime()).toBe(Date.UTC(2026, 8, 25, 15, 0, 0))
  })

  it('interprets date-only strings as UTC midnight', () => {
    const result = parseStoredDate('2026-09-25')
    expect(result.getTime()).toBe(Date.UTC(2026, 8, 25, 0, 0, 0))
  })

  it('passes through ISO strings with explicit timezone', () => {
    const result = parseStoredDate('2026-09-25T15:00:00Z')
    expect(result.getTime()).toBe(Date.UTC(2026, 8, 25, 15, 0, 0))
  })

  it('handles offset-aware strings unchanged', () => {
    const result = parseStoredDate('2026-09-25T17:00:00+02:00')
    expect(result.getTime()).toBe(Date.UTC(2026, 8, 25, 15, 0, 0))
  })

  it('returns null for empty input', () => {
    expect(parseStoredDate('')).toBeNull()
    expect(parseStoredDate(null)).toBeNull()
    expect(parseStoredDate(undefined)).toBeNull()
  })
})

describe('toUtcDbString', () => {
  it('converts a local Date to a UTC Y-m-d H:i:s string', () => {
    const date = new Date(Date.UTC(2026, 8, 25, 15, 30, 45))
    expect(toUtcDbString(date)).toBe('2026-09-25 15:30:45')
  })

  it('produces a round-trippable value with parseStoredDate', () => {
    const original = new Date(Date.UTC(2026, 11, 31, 23, 59, 59))
    const restored = parseStoredDate(toUtcDbString(original))
    expect(restored.getTime()).toBe(original.getTime())
  })
})
