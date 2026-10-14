import {computed} from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Derives typed computed properties from a schedule ref.
 *
 * @param {import('vue').Ref<import('../types/schedule').Schedule | null>} scheduleRef
 * @returns {{
 *   rooms: import('vue').ComputedRef<import('../types/schedule').Room[]>,
 *   timeslots: import('vue').ComputedRef<import('../types/schedule').Timeslot[]>,
 *   sessions: import('vue').ComputedRef<import('../types/schedule').Session[]>,
 *   topics: import('vue').ComputedRef<import('../types/schedule').Topic[]>,
 *   unassignedSessions: import('vue').ComputedRef<import('../types/schedule').Session[]>,
 *   defaultValues: {
 *     topic: string,
 *     title: string,
 *     speakers: string,
 *     format: string
 *   },
 *   getTopicIndex: (topicName: string) => number,
 *   getSession: (timeslot: string, room: string) => import('../types/schedule').Session | undefined
 * }}
 */
export function useSchedule(scheduleRef) {
  const { t } = useI18n()

  const defaultTopic = t('schedule.defaultTopic')
  const defaultValues = {
    topic: defaultTopic,
    title: t('schedule.defaultTitle'),
    speakers: t('schedule.defaultSpeakers'),
    format: t('schedule.defaultFormat'),
  };

  const rooms = computed(() => scheduleRef.value?.data?.rooms || [])
  const timeslots = computed(() => scheduleRef.value?.data?.timeslots || [])
  const sessions = computed(() => scheduleRef.value?.data?.sessions || [])
  const unassignedSessions = computed(() =>
    sessions.value.filter(
      (s) =>s.timeslot === 'buffer'
    )
  )

  const topics = computed(() => {
    const sessionTopicNames = sessions.value
      .map((s) => s.topic)
      .filter((t) => t && t !== defaultTopic)
    const explicitTopics = (scheduleRef.value?.data?.topics || []).map(t => typeof t === 'string' ? { name: t } : t)

    const uniqueNames = [...new Set([...explicitTopics.map(t => t.name), ...sessionTopicNames])]

    return uniqueNames.map((name) => {
      const explicitTopic = explicitTopics.find(t => t.name === name)
      if (explicitTopic && typeof explicitTopic === 'object' && explicitTopic.colorIndex !== undefined) {
        return { name, colorIndex: explicitTopic.colorIndex }
      }
      return { name, colorIndex: 0 }
    })
  })

  const getTopicIndex = (topicName) => {
    const topic = topics.value.find(t => t.name === topicName)
    return (topic ? topic?.colorIndex : null) || 0
  }

  const getSession = (timeslot, room) => {
    return sessions.value.find(
      (s) => s.timeslot === timeslot && s.room === room
    )
  }

  return {
    rooms,
    timeslots,
    sessions,
    topics,
    unassignedSessions,
    defaultValues,
    getTopicIndex,
    getSession
  }
}
