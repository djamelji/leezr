<script setup>
import { PerfectScrollbar } from 'vue3-perfect-scrollbar'

const props = defineProps({
  folderCounts: { type: Object, default: () => ({}) },
  currentFolder: { type: String, default: 'inbox' },
  currentLabel: { type: String, default: null },
  folders: { type: Array, required: true },
  labels: { type: Array, required: true },
})

const emit = defineEmits(['update:currentFolder', 'update:currentLabel', 'compose'])

const { t } = useI18n()

const folderBadgeColor = key => {
  if (key === 'inbox') return 'primary'
  if (key === 'spam') return 'error'
  if (key === 'starred') return 'warning'

  return 'default'
}
</script>

<template>
  <div class="d-flex flex-column h-100">
    <!-- Compose button -->
    <div class="px-6 pb-5 pt-6">
      <VBtn
        block
        @click="emit('compose')"
      >
        {{ t('emailInbox.composeBtn') }}
      </VBtn>
    </div>

    <!-- Folders + Labels -->
    <PerfectScrollbar
      :options="{ wheelPropagation: false }"
      class="h-100"
    >
      <!-- Folders -->
      <ul class="email-filters py-4">
        <li
          v-for="folder in folders"
          :key="folder.key"
          :class="[
            'd-flex align-center cursor-pointer',
            currentFolder === folder.key && !currentLabel && 'email-filter-active text-primary',
          ]"
          @click="emit('update:currentFolder', folder.key)"
        >
          <VIcon
            :icon="folder.icon"
            class="me-2"
            size="20"
          />
          <div class="text-base">
            {{ t(`emailInbox.folders.${folder.key}`) }}
          </div>

          <VSpacer />

          <VChip
            v-if="folderCounts[folder.key]"
            :color="folderBadgeColor(folder.key)"
            label
            size="small"
            class="rounded-xl px-3"
          >
            {{ folderCounts[folder.key] }}
          </VChip>
        </li>
      </ul>

      <!-- Labels -->
      <ul class="email-labels py-4">
        <div class="text-caption text-disabled mb-4 px-6">
          {{ t('emailInbox.labels.title') }}
        </div>
        <li
          v-for="label in labels"
          :key="label.title"
          :class="[
            'cursor-pointer d-flex align-center',
            currentLabel === label.title && 'email-label-active text-primary',
          ]"
          @click="emit('update:currentLabel', label.title)"
        >
          <VIcon
            icon="tabler-circle-filled"
            :color="label.color"
            class="me-2"
            size="12"
          />
          <div class="text-body-1 text-high-emphasis">
            {{ t(`emailInbox.labels.${label.title}`) }}
          </div>
        </li>
      </ul>
    </PerfectScrollbar>
  </div>
</template>

<style lang="scss">
.email-filters,
.email-labels {
  list-style: none;
  padding: 0;
  margin: 0;

  .email-filter-active,
  .email-label-active {
    &::after {
      position: absolute;
      background: currentcolor;
      block-size: 100%;
      content: "";
      inline-size: 3px;
      inset-block-start: 0;
      inset-inline-start: 0;
    }
  }
}

.email-filters {
  > li {
    position: relative;
    margin-block-end: 4px;
    padding-block: 4px;
    padding-inline: 24px;
  }
}

.email-labels {
  > li {
    position: relative;
    margin-block-end: 0.75rem;
    padding-inline: 24px;
  }
}
</style>
