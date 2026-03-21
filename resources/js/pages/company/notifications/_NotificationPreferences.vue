<script setup>
/**
 * Notification Preferences — Commercial bundles (1 per category)
 * ADR-382: Permission-filtered, commercial labels, locked security
 */
import { useNotificationStore } from '@/core/stores/notification'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const store = useNotificationStore()
const { toast } = useAppToast()
const saving = ref(false)
const localBundles = ref([])

onMounted(async () => {
  await store.fetchPreferences()
  localBundles.value = store.preferences.map(b => ({ ...b }))
})

const save = async () => {
  saving.value = true
  try {
    const bundles = localBundles.value.map(b => ({
      category: b.category,
      in_app: b.locked ? true : b.in_app,
      email: b.email,
    }))

    await store.updatePreferences(bundles)
    toast(t('common.saved'), 'success')
  }
  catch {
    toast(t('common.operationFailed'), 'error')
  }
  finally {
    saving.value = false
  }
}

const reset = () => {
  localBundles.value = store.preferences.map(b => ({ ...b }))
}
</script>

<template>
  <VCard>
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
        >
          <VIcon icon="tabler-bell-cog" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('notifications.preferences') }}</VCardTitle>
      <VCardSubtitle>{{ t('notifications.preferencesDesc') }}</VCardSubtitle>
    </VCardItem>

    <VCardText>
      <!-- Loading -->
      <div
        v-if="!store.preferencesLoaded"
        class="text-center py-8"
      >
        <VProgressCircular indeterminate />
      </div>

      <!-- Bundle list -->
      <VList
        v-else
        class="card-list"
      >
        <VListItem
          v-for="bundle in localBundles"
          :key="bundle.category"
          class="py-4"
        >
          <template #prepend>
            <VAvatar
              :color="bundle.color || 'primary'"
              variant="tonal"
              rounded
            >
              <VIcon :icon="bundle.icon" />
            </VAvatar>
          </template>

          <VListItemTitle class="font-weight-medium">
            {{ t(`notifications.bundle.${bundle.category}.label`) }}
          </VListItemTitle>
          <VListItemSubtitle class="text-wrap">
            {{ t(`notifications.bundle.${bundle.category}.description`) }}
          </VListItemSubtitle>

          <template #append>
            <div class="d-flex align-center gap-4">
              <!-- In-App switch -->
              <div
                class="d-flex flex-column align-center"
                style="min-inline-size: 60px;"
              >
                <span class="text-caption text-disabled mb-1">
                  {{ t('notifications.channelInApp') }}
                </span>
                <VSwitch
                  v-model="bundle.in_app"
                  :disabled="bundle.locked"
                  hide-details
                  density="compact"
                  color="primary"
                />
              </div>

              <!-- Email switch -->
              <div
                class="d-flex flex-column align-center"
                style="min-inline-size: 60px;"
              >
                <span class="text-caption text-disabled mb-1">
                  {{ t('notifications.channelEmail') }}
                </span>
                <VSwitch
                  v-model="bundle.email"
                  hide-details
                  density="compact"
                  color="primary"
                />
              </div>
            </div>
          </template>
        </VListItem>
      </VList>

      <!-- Locked notice -->
      <VAlert
        v-if="localBundles.some(b => b.locked)"
        type="info"
        variant="tonal"
        class="mt-4"
        density="compact"
      >
        {{ t('notifications.lockedNotice') }}
      </VAlert>
    </VCardText>

    <VCardActions>
      <VSpacer />
      <VBtn
        variant="outlined"
        @click="reset"
      >
        {{ t('common.cancel') }}
      </VBtn>
      <VBtn
        color="primary"
        :loading="saving"
        @click="save"
      >
        {{ t('common.save') }}
      </VBtn>
    </VCardActions>
  </VCard>
</template>
