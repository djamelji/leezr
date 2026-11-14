<script setup>
// ADR-776 (Company Experience 2.0 / UX1) — « À traiter » : what needs the viewer's intervention,
// contributed by the ACTIVE modules and filtered by the viewer's real rights (server-side,
// GET /dashboard/priorities). Each row opens the treatment itself. Local states only : a failure
// here never blocks the rest of the home.
import { $api } from '@/utils/api'

const { t, te } = useI18n()

const state = ref('loading') // loading | ready | error
const priorities = ref([])
const unavailable = ref([])

const load = async () => {
  state.value = 'loading'
  try {
    const data = await $api('/dashboard/priorities')

    priorities.value = data.priorities ?? []
    unavailable.value = data.unavailable ?? []
    state.value = 'ready'
  }
  catch {
    state.value = 'error'
  }
}

onMounted(load)
defineExpose({ load })

const label = key => (te(`home.priorities.${key}`) ? t(`home.priorities.${key}`) : key)
const color = severity => ({ error: 'error', warning: 'warning', info: 'info' }[severity] ?? 'primary')
</script>

<template>
  <VCard
    class="h-100"
    data-test="home-priorities"
  >
    <VCardItem class="pb-2">
      <VCardTitle class="text-h6">
        {{ t('home.sections.todo') }}
      </VCardTitle>
      <template
        v-if="state === 'ready' && priorities.length"
        #append
      >
        <span class="text-body-2 text-medium-emphasis">{{ t('home.todoCount', priorities.length) }}</span>
      </template>
    </VCardItem>

    <VCardText
      v-if="state === 'loading'"
      class="pt-0"
    >
      <VSkeletonLoader type="list-item-avatar@3" />
    </VCardText>

    <VCardText
      v-else-if="state === 'error'"
      class="pt-0"
    >
      <VAlert
        type="warning"
        variant="tonal"
        density="compact"
        data-test="home-priorities-error"
      >
        <div class="d-flex align-center flex-wrap gap-2">
          <span>{{ t('home.todoError') }}</span>
          <VSpacer />
          <VBtn
            size="small"
            variant="text"
            @click="load"
          >
            {{ t('common.retry') }}
          </VBtn>
        </div>
      </VAlert>
    </VCardText>

    <template v-else>
      <VList
        v-if="priorities.length"
        lines="one"
        density="comfortable"
        class="py-0"
      >
        <VListItem
          v-for="p in priorities"
          :key="p.key"
          :to="p.to"
          :data-test="`home-priority-${p.key}`"
        >
          <template #prepend>
            <VAvatar
              :color="color(p.severity)"
              variant="tonal"
              rounded
              size="34"
              class="me-1"
            >
              <VIcon
                :icon="p.icon"
                size="20"
              />
            </VAvatar>
          </template>
          <VListItemTitle class="text-body-1">
            {{ label(p.key) }}
          </VListItemTitle>
          <template #append>
            <span class="text-h6 font-weight-medium me-2">{{ p.count }}</span>
            <VIcon
              icon="tabler-chevron-right"
              size="18"
              class="text-medium-emphasis"
            />
          </template>
        </VListItem>
      </VList>

      <VCardText
        v-else
        class="d-flex align-center gap-3 pt-0"
        data-test="home-priorities-clear"
      >
        <VIcon
          icon="tabler-circle-check"
          color="success"
        />
        <div>
          <div class="text-body-1">
            {{ t('home.allClear.title') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('home.allClear.subtitle') }}
          </div>
        </div>
      </VCardText>

      <VCardText
        v-if="unavailable.length"
        class="pt-0 text-body-2 text-medium-emphasis"
        data-test="home-priorities-partial"
      >
        {{ t('home.todoPartial') }}
      </VCardText>
    </template>
  </VCard>
</template>
