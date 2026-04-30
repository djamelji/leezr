<script setup>
import { $platformApi } from '@/utils/platformApi'

// Sub-component — no definePage — hub: platform/settings/[tab].vue

const { t } = useI18n()

const isLoading = ref(true)
const flags = ref([])
const showCreateDialog = ref(false)
const newFlag = ref({ key: '', description: '', enabled_globally: false })
const isSaving = ref(false)

const load = async () => {
  isLoading.value = true
  try {
    flags.value = await $platformApi('/feature-flags')
  } catch {
    // silent
  } finally {
    isLoading.value = false
  }
}

const createFlag = async () => {
  isSaving.value = true
  try {
    await $platformApi('/feature-flags', { method: 'POST', body: newFlag.value })
    showCreateDialog.value = false
    newFlag.value = { key: '', description: '', enabled_globally: false }
    await load()
  } finally {
    isSaving.value = false
  }
}

const toggleFlag = async flag => {
  try {
    await $platformApi(`/feature-flags/${flag.key}/toggle`, {
      method: 'POST',
      body: { enabled: !flag.enabled_globally },
    })
    flag.enabled_globally = !flag.enabled_globally
  } catch {
    // silent
  }
}

const deleteFlag = async flag => {
  try {
    await $platformApi(`/feature-flags/${flag.key}`, { method: 'DELETE' })
    await load()
  } catch {
    // silent
  }
}

const overrideCount = flag => {
  return Object.keys(flag.company_overrides || {}).length
}

onMounted(load)
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4">
          {{ t('featureFlags.title') }}
        </h4>
        <p class="text-body-2 text-medium-emphasis mb-0">
          {{ t('featureFlags.subtitle') }}
        </p>
      </div>
      <VBtn
        color="primary"
        @click="showCreateDialog = true"
      >
        <VIcon
          start
          icon="tabler-plus"
        />
        {{ t('featureFlags.create') }}
      </VBtn>
    </div>

    <VSkeletonLoader
      v-if="isLoading && !flags.length"
      type="table"
    />

    <VCard v-if="flags.length || !isLoading">
      <VTable>
        <thead>
          <tr>
            <th>{{ t('featureFlags.key') }}</th>
            <th>{{ t('featureFlags.description') }}</th>
            <th class="text-center">
              {{ t('featureFlags.global') }}
            </th>
            <th class="text-center">
              {{ t('featureFlags.overrides') }}
            </th>
            <th class="text-end">
              {{ t('common.actions') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="flag in flags"
            :key="flag.key"
          >
            <td>
              <code>{{ flag.key }}</code>
            </td>
            <td>{{ flag.description || '—' }}</td>
            <td class="text-center">
              <VSwitch
                :model-value="flag.enabled_globally"
                color="success"
                hide-details
                density="compact"
                @update:model-value="toggleFlag(flag)"
              />
            </td>
            <td class="text-center">
              <VChip
                v-if="overrideCount(flag)"
                size="small"
                color="info"
              >
                {{ overrideCount(flag) }}
              </VChip>
              <span
                v-else
                class="text-disabled"
              >—</span>
            </td>
            <td class="text-end">
              <VBtn
                icon
                variant="text"
                size="small"
                color="error"
                @click="deleteFlag(flag)"
              >
                <VIcon
                  icon="tabler-trash"
                  size="18"
                />
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VCard
      v-if="!flags.length && !isLoading"
      class="text-center pa-8"
    >
      <VIcon
        icon="tabler-flag"
        size="48"
        class="text-disabled mb-3"
      />
      <p class="text-body-1 text-medium-emphasis">
        {{ t('featureFlags.empty') }}
      </p>
    </VCard>

    <!-- Create Dialog -->
    <VDialog
      v-model="showCreateDialog"
      max-width="500"
    >
      <VCard>
        <VCardTitle>{{ t('featureFlags.createTitle') }}</VCardTitle>
        <VCardText>
          <AppTextField
            v-model="newFlag.key"
            :label="t('featureFlags.key')"
            class="mb-4"
            placeholder="my.feature.flag"
          />
          <AppTextField
            v-model="newFlag.description"
            :label="t('featureFlags.description')"
            class="mb-4"
          />
          <VSwitch
            v-model="newFlag.enabled_globally"
            :label="t('featureFlags.enabledGlobally')"
            color="success"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="showCreateDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="isSaving"
            :disabled="!newFlag.key"
            @click="createFlag"
          >
            {{ t('common.create') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
