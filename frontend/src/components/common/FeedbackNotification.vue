<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { ref, onMounted, computed } from 'vue';
import CrossIcon from '../icons/CrossIcon.vue';
import BaseButton from '../base/BaseButton.vue';
import PencilIcon from '../icons/PencilIcon.vue';

const props = defineProps({
  scheduleId: {
    type: Number,
    required: true
  },
  eventName: {
    type: String,
    required: true
  }
});

const emit = defineEmits(['open-feedback']);

const isVisible = ref(false);

// Dynamic key based on the scheduleId prop
const storageKey = computed(() => `feedback_dismissed_${props.scheduleId}`);

onMounted(() => {
  const dismissed = localStorage.getItem(storageKey.value);
  if (!dismissed) {
    // 2-second delay to let the page settle
    setTimeout(() => {
      isVisible.value = true;
    }, 2000);
  }
});

const dismissFeedback = () => {
  isVisible.value = false;
  localStorage.setItem(storageKey.value, 'true');
};

const handleFeedbackClick = () => {
  emit('open-feedback', props.scheduleId);
  dismissFeedback();
};
</script>

<template>
  <!-- Vue Transition for smooth entry/exit -->
  <Transition
      enter-active-class="transition duration-500 ease-out"
      enter-from-class="translate-y-full opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition duration-300 ease-in"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-full opacity-0"
  >
    <div
        v-if="isVisible"
        class="fixed bottom-4 right-4 left-4 z-50 md:left-auto md:w-sm"
    >
      <div class="relative flex flex-col gap-4 rounded-xl border border-gray-light/60 bg-surface p-5 shadow-2xl">

        <!-- Close Button -->
        <button
            class="absolute top-3 right-3 text-gray-mid transition-colors hover:text-primary"
            :aria-label="t('common.close')"
            @click="dismissFeedback"
        >
          <CrossIcon class="size-4"></CrossIcon>
        </button>

        <!-- Content Area -->
        <div class="flex items-start gap-4">
          <!-- Icon Container -->
          <div class="rounded-lg bg-primary/10 p-2 text-primary italic font-serif text-xl">
            <PencilIcon class="size-6"/>
          </div>
          <div class="pr-6">
            <h3 class="font-bold tracking-tight text-primary">{{ t('feedback.howWasTheEvent') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-gray-mid">
              {{ t('feedback.shareThoughts', { name: eventName }) }}
            </p>
          </div>
        </div>

        <!-- Action Buttons -->
        <div class="ml-14 flex items-center gap-3">
          <BaseButton
              variant="secondary"
              @click="handleFeedbackClick"
          >
            {{ t('feedback.leaveFeedback') }}
          </BaseButton>
          <BaseButton
              variant="ghost"
              @click="dismissFeedback"
          >
            {{ t('feedback.dismiss') }}
          </BaseButton>
        </div>
      </div>
    </div>
  </Transition>
</template>
