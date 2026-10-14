/**
 * Shared TypeScript interfaces for the barcamp schedule domain.
 *
 * These types are the single source of truth for the Schedule data shape.
 * JS files reference them via JSDoc @type annotations:
 *   /** @type {import('../types/schedule').Schedule} *\/
 */

/** Room capacity / session audience-interest indicator */
export type SizeCategory = 'S' | 'M' | 'L'

export interface Room {
  name: string
  size?: SizeCategory
}

export interface Timeslot {
  name: string
  /** When true the timeslot row is hidden in view-only mode */
  hidden?: boolean
}

export interface Session {
  /** References Timeslot.name. Use the literal 'buffer' for unassigned sessions. */
  timeslot: string
  /** References Room.name. Buffer sessions use 'buffer-0', 'buffer-1', … */
  room: string
  title: string
  speakers?: string
  format?: string
  topic: string
  audience_interest?: SizeCategory
  link?: string
}

export interface Topic {
  name: string
  /** Index 0–7, maps to CSS class topic-0 … topic-7 */
  colorIndex?: number
}

export interface FeedbackQuestion {
  id: string
  question: string
  type: 'rating' | 'text' | 'free_text' | 'multiple_choice'
  required?: boolean
  order?: number
  options?: string[]
}

export interface ScheduleData {
  rooms: Room[]
  timeslots: Timeslot[]
  sessions: Session[]
  topics: Topic[]
  ask_for_feedback?: boolean
  feedback_questions?: FeedbackQuestion[]
}

/** Full Schedule object as returned by the API */
export interface Schedule {
  id: number
  hashed_id: string
  name: string
  /** Incremented on every save; used for optimistic concurrency control */
  version: number
  data: ScheduleData
  /** Optional event date in YYYY-MM-DD format */
  date?: string | null
  created_at?: string
  updated_at?: string
}

/**
 * One entry in the documentation_counts array returned alongside schedule data.
 * The special sentinel timeslot='event' / room='event' represents event-level feedback.
 */
export interface DocumentationCount {
  timeslot: string
  room: string
  count: number
  avg_rating?: number | null
}

/** Tracks which cell/field is currently being inline-edited */
export interface ActiveEdit {
  type: string
  id: string
  field: string | null
}

export type SaveStatus = '' | 'saving' | 'saved' | 'error'
