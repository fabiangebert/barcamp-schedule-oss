<template>
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-2 backdrop-blur-sm"
    @click.self="$emit('close')"
  >
    <div
      class="flex h-full max-h-[95vh] w-full max-w-xl flex-col rounded-xl bg-surface shadow-2xl overflow-hidden border border-gray-light/40"
    >
      <!-- Header -->
      <div
        class="flex items-center justify-between border-b border-gray-light/40 px-4 py-2 bg-surface-sunken"
      >
        <div>
          <h2 class="text-sm font-black tracking-tight text-primary uppercase">
            {{ session?.title || (docs[0] && docs[0].session_title) || t('schedule.documentation') }}
          </h2>
          <div
            class="text-3xs font-black uppercase tracking-widest text-gray-mid/50 mt-0.5"
          >
            <div class="flex flex-wrap items-center gap-x-1.5">
              <span>{{ session?.timeslot }} • {{ session?.room }}</span>
              <template v-if="session?.speakers">
                <span class="opacity-40">•</span>
                <span class="text-primary/60">{{ session.speakers }}</span>
              </template>
            </div>
          </div>
        </div>
        <button
          class="rounded-md p-1 text-gray-mid hover:bg-gray-light/20 hover:text-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
          @click="$emit('close')"
        >
          <CrossIcon class="w-[18px] h-[18px]" />
        </button>
      </div>

      <!-- Content -->
      <div
        ref="scrollContainer"
        class="flex-1 overflow-y-auto p-4 space-y-4 bg-surface"
      >
        <div
          v-if="loading"
          class="flex flex-col items-center justify-center py-8 space-y-2"
        >
          <div
            class="w-6 h-6 border-3 border-primary border-t-transparent rounded-full animate-spin"
          ></div>
          <p
            class="text-3xs font-bold uppercase tracking-widest text-gray-mid animate-pulse"
          >
            {{ t('common.loading') }}
          </p>
        </div>

        <div
          v-else-if="docs.length === 0"
          class="flex flex-col items-center justify-center py-8 text-center space-y-2 opacity-40"
        >
          <DocumentEmptyIcon class="w-8 h-8 text-gray-mid" />
          <p class="text-3xs font-bold uppercase tracking-widest">
            {{ t('documentation.noDocsYet') }}
          </p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="doc in docs"
            :key="doc.id"
            class="group flex flex-col space-y-1.5 animate-in fade-in slide-in-from-bottom-2 duration-300"
          >
            <DocumentationItem
              :doc="doc"
              :can-delete="userId && !isGuest"
              :is-own="
                (isGuest && doc.guest_id === guestId) ||
                (!isGuest && doc.user_id && doc.user_id === userId)
              "
              variant="bubble"
              @delete="deleteDoc"
              @zoom="zoomedImage = $event"
            />
          </div>
        </div>
      </div>

      <!-- Footer / Input -->
      <div class="border-t border-gray-light/30 bg-surface-raised p-4">
        <form class="space-y-3" @submit.prevent="handleSubmit">
          <div v-if="!isPublicContribution" class="mb-3 px-1">
            <p
              class="text-3xs font-medium text-gray-mid flex items-center gap-1.5"
            >
              <span
                class="inline-block w-1.5 h-1.5 rounded-full bg-secondary"
              ></span>
              {{ t('documentation.guestVisibilityNote') }}
            </p>
          </div>

          <div class="relative">
            <textarea
              v-model="newComment"
              :placeholder="
                !isGuest
                  ? t('documentation.writeComment')
                  : t('documentation.writeAsGuest')
              "
              class="w-full rounded-md border border-gray-light/50 bg-surface p-3 text-base outline-none focus:border-primary focus:ring-2 focus:ring-primary/10 transition-all resize-none min-h-15"
              :disabled="submitting"
            ></textarea>

            <!-- Image/File preview -->
            <div
              v-if="selectedImages.length > 0"
              class="mt-2 flex flex-wrap gap-1.5 group"
            >
              <div
                v-for="(img, idx) in selectedImages"
                :key="idx"
                class="relative"
              >
                <div
                  v-if="img.type.startsWith('image/') && imagePreviews[idx]"
                  class="relative"
                >
                  <img
                    :src="imagePreviews[idx]"
                    class="h-12 w-12 rounded-md object-cover border-2 border-primary shadow-lg"
                  />
                </div>
                <div
                  v-else
                  class="flex h-12 w-12 items-center justify-center rounded-md border-2 border-primary bg-surface shadow-lg"
                >
                  <DocumentIcon class="w-6 h-6 text-primary" />
                </div>

                <!-- Progress overlay -->
                <div
                  v-if="img.uploading"
                  class="absolute inset-0 flex items-center justify-center bg-black/40 rounded-md"
                >
                  <div class="text-5xs font-black text-white">
                    {{ img.progress }}%
                  </div>
                </div>
                <div
                  v-if="img.error"
                  class="absolute inset-0 flex items-center justify-center bg-red-500/60 rounded-md"
                  :title="img.error"
                >
                  <CrossIcon class="w-4 h-4 text-white" />
                </div>

                <button
                  type="button"
                  class="absolute -top-1.5 -right-1.5 rounded-full bg-secondary p-0.5 text-white shadow-md hover:bg-secondary/80 focus:outline-none focus:ring-2 focus:ring-secondary/20"
                  @click="removeImage(idx)"
                >
                  <CrossIcon class="w-2.5 h-2.5" />
                </button>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-between gap-2">
            <div class="flex gap-1.5">
              <label
                class="flex cursor-pointer items-center justify-center rounded-md bg-surface p-2 text-primary border border-gray-light/50 hover:bg-gray-light/10 transition-all shadow-sm"
                :title="t('documentation.capturePhoto')"
              >
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  capture="environment"
                  multiple
                  class="sr-only"
                  :disabled="submitting"
                  @change="handleImageUpload"
                />
                <CameraIcon class="w-4 h-4" />
              </label>

              <label
                class="flex cursor-pointer items-center justify-center rounded-md bg-surface p-2 text-primary border border-gray-light/50 hover:bg-gray-light/10 transition-all shadow-sm"
                :title="t('documentation.uploadFile')"
              >
                <input
                  type="file"
                  accept="image/jpeg,image/png,image/webp,application/pdf"
                  multiple
                  class="sr-only"
                  :disabled="submitting"
                  @change="handleImageUpload"
                />
                <UploadIcon class="w-4 h-4" />
                <span
                  v-if="selectedImages.length > 0"
                  class="ml-1.5 text-4xs font-black uppercase"
                  >{{ selectedImages.length }}</span
                >
              </label>
            </div>

            <div class="flex items-center gap-2">
              <div
                class="flex items-center"
                :title="
                  isPublicContribution
                    ? t('documentation.visibility.user')
                    : t('documentation.visibility.guest')
                "
              >
                <EyeOffIcon
                  v-if="!isPublicContribution"
                  class="w-4 h-4 opacity-40 text-gray-mid"
                />
                <EyeIcon v-else class="w-4 h-4 opacity-40 text-gray-mid" />
              </div>

              <button
                type="submit"
                class="flex flex-1 items-center justify-center gap-1.5 rounded-md border border-surface-inverse bg-surface-inverse px-4 py-2 text-3xs font-black uppercase tracking-widest text-white hover:bg-surface-inverse/90 focus:outline-none focus:ring-2 focus:ring-primary/20 disabled:opacity-50"
                :disabled="submitting || (!newComment.trim() && selectedImages.length === 0)"
              >
                <span
                  v-if="submitting"
                  class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"
                ></span>
                <span v-else>{{ t('documentation.contribute') }}</span>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Image Modal (Zoom) -->
    <ImageZoom :src="zoomedImage" @close="zoomedImage = null" />
  </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n'
const { t } = useI18n()
import { onMounted } from 'vue'
import DocumentationItem from './DocumentationItem.vue'
import ImageZoom from '../common/ImageZoom.vue'
import CrossIcon from '../icons/CrossIcon.vue'
import DocumentEmptyIcon from '../icons/DocumentEmptyIcon.vue'
import CameraIcon from '../icons/CameraIcon.vue'
import DocumentIcon from '../icons/DocumentIcon.vue'
import UploadIcon from '../icons/UploadIcon.vue'
import EyeIcon from '../icons/EyeIcon.vue'
import EyeOffIcon from '../icons/EyeOffIcon.vue'
import { useDocumentationModal } from '../../composables/useDocumentationModal'

const props = defineProps({
  session: { type: Object, required: true },
  scheduleId: { type: String, required: true },
  userName: { type: String, required: true },
  isGuest: { type: Boolean, default: false },
  guestId: { type: String, default: '' },
  userId: { type: [Number, String], default: null },
  token: { type: String, default: '' },
  documentationCounts: { type: Object, default: null }
})

const emit = defineEmits(['close', 'notify'])

const {
  docs,
  loading,
  submitting,
  newComment,
  selectedImages,
  imagePreviews,
  zoomedImage,
  scrollContainer,
  isPublicContribution,
  fetchDocs,
  handleImageUpload,
  removeImage,
  submitDoc,
  deleteDoc
} = useDocumentationModal(props, emit, () => ({
  timeslot: props.session.timeslot,
  room: props.session.room
}), t)

const handleSubmit = async () => {
  if (!newComment.value.trim() && selectedImages.value.length === 0) return
  await submitDoc({ session_title: props.session?.title || '' })
}

onMounted(fetchDocs)
</script>

<style scoped>
.no-scrollbar::-webkit-scrollbar {
  display: none;
}
.no-scrollbar {
  -ms-overflow-style: none;
  scrollbar-width: none;
}
</style>
