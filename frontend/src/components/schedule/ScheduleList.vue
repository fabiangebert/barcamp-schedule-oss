<template>
  <div>
    <div class="mb-6 flex items-center justify-between">
      <h2 class="text-lg font-extrabold text-primary tracking-tight">{{ t('schedule.availableSchedules') }}</h2>
      <BaseButton
          v-if="user"
          @click="$emit('show-new-modal')"
      >
        {{ t('schedule.newSchedule') }}
      </BaseButton>
    </div>
    <div class="overflow-hidden rounded-xl border border-gray-light/50 shadow-sm mb-10">
      <table class="w-full border-collapse">
        <thead>
        <tr class="bg-surface-sunken">
          <th
              class="border-b border-gray-light/50 p-3 text-left text-xs font-bold uppercase tracking-wider text-primary/60 cursor-pointer hover:bg-surface-raised transition-colors"
              @click="$emit('toggle-sort', 'name')"
          >
            <div class="flex items-center gap-1.5">
              <span>{{ t('common.name') }}</span>
              <span v-if="sortColumn === 'name'" class="text-primary">
                <ChevronUpIcon v-if="sortOrder === 'asc'" class="h-3 w-3" />
                <ChevronDownIcon v-else class="h-3 w-3" />
              </span>
              <SelectorIcon v-else class="h-3 w-3 text-gray-mid/20" />
            </div>
          </th>
          <th
              class="border-b border-gray-light/50 p-3 text-left text-xs font-bold uppercase tracking-wider text-primary/60 cursor-pointer hover:bg-surface-raised transition-colors"
              @click="$emit('toggle-sort', 'date')"
          >
            <div class="flex items-center gap-1.5">
              <span>{{ t('common.date') }}</span>
              <span v-if="sortColumn === 'date'" class="text-primary">
                <ChevronUpIcon v-if="sortOrder === 'asc'" class="h-3 w-3" />
                <ChevronDownIcon v-else class="h-3 w-3" />
              </span>
              <SelectorIcon v-else class="h-3 w-3 text-gray-mid/20" />
            </div>
          </th>
          <th class="border-b border-gray-light/50 p-3 text-left text-xs font-bold uppercase tracking-wider text-primary/60">
            {{ t('common.actions') }}
          </th>
        </tr>
        </thead>
        <tbody>
        <tr
v-for="s in schedules"
:key="s.hashed_id"
            class="hover:bg-primary/[0.02] transition-colors last:border-b-0">
          <td class="border-b border-gray-light/30 p-3">
            <div class="flex items-center gap-2 group/row">
              <a
                  :href="'?schedule=' + s.hashed_id"
                  class="text-sm font-bold text-primary hover:underline"
                  @click.prevent="$emit('select-schedule', s)"
              >
                {{ s.name }}
              </a>
            </div>
          </td>
          <td class="border-b border-gray-light/30 p-3">
            <div class="flex items-center gap-2">
              <span class="text-sm text-gray-mid flex items-center gap-1.5">
                <CalendarIcon v-if="s.date" class="h-3.5 w-3.5 text-gray-mid/60" />
                {{ s.date || t('schedule.noDateSet') }}
              </span>
            </div>
          </td>
          <td class="border-b border-gray-light/30 p-3">
            <div class="flex items-center gap-2">
              <BaseButton
                  v-if="user"
                  variant="ghost"
                  class="!text-xs !font-bold"
                  :title="t('schedule.share')"
                  @click="$emit('share', s)"
              >
                {{ t('schedule.share') }}
              </BaseButton>
              <BaseButton
                  v-if="user && isAdmin"
                  variant="secondary"
                  class="!text-xs !font-bold"
                  :title="t('common.delete')"
                  @click="$emit('request-delete', s)"
              >
                {{ t('common.delete') }}
              </BaseButton>
            </div>
          </td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import BaseButton from '../base/BaseButton.vue'
import ChevronUpIcon from '../icons/ChevronUpIcon.vue'
import ChevronDownIcon from '../icons/ChevronDownIcon.vue'
import SelectorIcon from '../icons/SelectorIcon.vue'
import CalendarIcon from '../icons/CalendarIcon.vue'

defineProps({
  schedules: { type: Array, default: () => [] },
  user: { type: Object, default: null },
  isAdmin: { type: Boolean, default: false },
  sortColumn: { type: String, default: 'name' },
  sortOrder: { type: String, default: 'asc' }
})

defineEmits([
  'show-new-modal',
  'toggle-sort',
  'request-delete',
  'select-schedule',
  'share'
])
</script>
