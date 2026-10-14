<template>
  <div
    class="color-picker-popover cursor-default absolute top-full left-0 mt-2 p-3 bg-surface rounded-lg shadow-xl border border-gray-light/30 z-[100] flex flex-col gap-3 min-w-50"
    @click.stop
  >
    <!-- Name Input Field -->
    <div class="flex flex-col gap-1.5">
      <label class="text-4xs font-black uppercase tracking-widest text-gray-mid/50">{{ t('schedule.topicName') }}</label>
      <input
        v-model="localName"
        type="text"
        class="w-full px-2 py-1.5 text-2xs font-bold border text-primary border-gray-light/30 rounded focus:outline-none focus:border-primary"
        :placeholder="t('schedule.topicName')"
      />
    </div>

    <!-- Color Selection -->
    <div class="flex flex-col gap-1.5">
      <label class="text-4xs font-black uppercase tracking-widest text-gray-mid/50">{{ t('schedule.color') }}</label>
      <div class="flex gap-2">
        <div
v-for="n in 6"
:key="n-1"
             class="w-6 h-6 rounded-full cursor-pointer hover:scale-110 transition-transform border border-black/5"
             :class="[`topic-${n-1}`, localColorIndex === n-1 ? 'ring-2 ring-primary ring-offset-1' : '']"
             @click="localColorIndex = n-1"
        ></div>
      </div>
    </div>

    <!-- Footer Actions -->
    <div v-if="showDelete || showDone" class="flex justify-between pt-1 border-t border-gray-light/20 gap-2">
      <div class="flex gap-2">
        <BaseButton
          v-if="showDelete"
          variant="ghost"
          size="sm"
          class="!text-secondary hover:!bg-secondary/10"
          @click.stop="$emit('delete')"
        >
          {{ t('common.delete') }}
        </BaseButton>
        <BaseButton
          variant="ghost"
          size="sm"
          class="!text-gray-mid hover:!bg-gray-light/10"
          @click.stop="$emit('cancel')"
        >
          {{ t('common.cancel') }}
        </BaseButton>
      </div>

      <BaseButton variant="primary" size="sm" @click.stop="$emit('confirm', { name: localName, colorIndex: localColorIndex })">
        {{ t('common.done') }}
      </BaseButton>
    </div>
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref } from 'vue'
import BaseButton from '../base/BaseButton.vue'

const props = defineProps({
  name: {
    type: String,
    required: true
  },
  colorIndex: {
    type: Number,
    required: true
  },
  showDelete: {
    type: Boolean,
    default: false
  },
  showDone: {
    type: Boolean,
    default: true
  }
})

defineEmits([
  'confirm',
  'delete',
  'cancel'
])

const localName = ref(props.name)
const localColorIndex = ref(props.colorIndex)
</script>
