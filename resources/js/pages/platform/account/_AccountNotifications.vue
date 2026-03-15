<script setup>
import { $platformApi } from '@/utils/platformApi'

const { t } = useI18n()
const saving = ref(false)
const loading = ref(false)
const localPrefs = ref([])

const categoryLabels = {
  billing: t('notifications.categoryBilling'),
  members: t('notifications.categoryMembers'),
  modules: t('notifications.categoryModules'),
  security: t('notifications.categorySecurity'),
  system: t('notifications.categorySystem'),
}

onMounted(async () => {
  loading.value = true
  try {
    const data = await $platformApi('/me/notification-preferences')

    localPrefs.value = (data.preferences || []).map(p => ({
      ...p,
      in_app: (p.channels || []).includes('in_app'),
      email: (p.channels || []).includes('email'),
    }))
  }
  finally {
    loading.value = false
  }
})

const groupedPrefs = computed(() => {
  const groups = {}
  for (const p of localPrefs.value) {
    const cat = p.category || 'system'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(p)
  }

  return groups
})

const save = async () => {
  saving.value = true
  try {
    const prefs = localPrefs.value.map(p => ({
      topic_key: p.key,
      channels: [
        ...(p.in_app ? ['in_app'] : []),
        ...(p.email ? ['email'] : []),
      ],
    }))

    await $platformApi('/me/notification-preferences', {
      method: 'PUT',
      body: { preferences: prefs },
    })
  }
  finally {
    saving.value = false
  }
}

const reset = async () => {
  loading.value = true
  try {
    const data = await $platformApi('/me/notification-preferences')

    localPrefs.value = (data.preferences || []).map(p => ({
      ...p,
      in_app: (p.channels || []).includes('in_app'),
      email: (p.channels || []).includes('email'),
    }))
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardTitle>{{ t('notifications.preferences') }}</VCardTitle>
    <VCardSubtitle>{{ t('platformAccount.notificationsDesc') }}</VCardSubtitle>

    <VCardText>
      <div
        v-if="loading"
        class="text-center py-8"
      >
        <VProgressCircular indeterminate />
      </div>

      <template v-else>
        <template
          v-for="(prefs, category) in groupedPrefs"
          :key="category"
        >
          <h6 class="text-h6 mb-3 mt-4">
            {{ categoryLabels[category] || category }}
          </h6>

          <VTable
            class="text-no-wrap mb-4"
            density="compact"
          >
            <thead>
              <tr>
                <th style="width: 400px">
                  {{ t('notifications.topic') }}
                </th>
                <th class="text-center">
                  In-App
                </th>
                <th class="text-center">
                  Email
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="pref in prefs"
                :key="pref.key"
              >
                <td>
                  <div class="d-flex align-center gap-2">
                    <VIcon
                      :icon="pref.icon"
                      size="18"
                    />
                    {{ pref.label }}
                  </div>
                </td>
                <td class="text-center">
                  <VCheckbox
                    v-model="pref.in_app"
                    hide-details
                    density="compact"
                  />
                </td>
                <td class="text-center">
                  <VCheckbox
                    v-model="pref.email"
                    hide-details
                    density="compact"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>
        </template>
      </template>
    </VCardText>

    <VCardActions v-if="!loading">
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
