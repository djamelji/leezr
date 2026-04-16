<script setup>
import { $platformApi } from '@/utils/platformApi'

const { toast } = useAppToast()
const { t } = useI18n()

const rules = ref([])
const templates = ref([])
const isLoading = ref(true)

// Edit / Create dialog
const isDialogOpen = ref(false)
const isCreating = ref(false)
const editingRule = ref(null)
const isSaving = ref(false)
const editFormRef = ref()
const editForm = ref({
  trigger_event: '',
  template_key: '',
  timing: 'immediate',
  frequency: null,
  delay_value: 0,
  delay_unit: 'days',
  max_sends: 1,
  is_active: true,
})

const timingOptions = [
  { title: t('email.timingImmediate'), value: 'immediate' },
  { title: t('email.timingDelayed'), value: 'delayed' },
  { title: t('email.timingRecurring'), value: 'recurring' },
]

const frequencyOptions = [
  { title: t('email.freqDaily'), value: 'daily' },
  { title: t('email.freqEvery2Days'), value: 'every_2_days' },
  { title: t('email.freqEvery3Days'), value: 'every_3_days' },
  { title: t('email.freqWeekly'), value: 'weekly' },
  { title: t('email.freqBiWeekly'), value: 'bi_weekly' },
  { title: t('email.freqMonthly'), value: 'monthly' },
]

const delayUnitOptions = [
  { title: t('email.days'), value: 'days' },
  { title: t('email.hours'), value: 'hours' },
  { title: t('email.minutes'), value: 'minutes' },
]

const templateOptions = computed(() =>
  templates.value.map(t => ({ title: `${t.name} (${t.key})`, value: t.key })),
)

const fetchAll = async () => {
  isLoading.value = true
  try {
    const [rulesData, tplData] = await Promise.all([
      $platformApi('/email/orchestration'),
      $platformApi('/email/templates/configurable'),
    ])

    rules.value = rulesData.rules
    templates.value = tplData.templates
  }
  catch (e) {
    toast(t('email.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const toggleRule = async rule => {
  try {
    await $platformApi(`/email/orchestration/${rule.id}`, {
      method: 'PUT',
      body: { is_active: !rule.is_active },
    })
    rule.is_active = !rule.is_active
    toast(rule.is_active ? t('email.ruleEnabled') : t('email.ruleDisabled'), 'success')
  }
  catch (e) {
    toast(e.message || t('email.saveError'), 'error')
  }
}

const openCreate = () => {
  isCreating.value = true
  editingRule.value = null
  editForm.value = {
    trigger_event: '',
    template_key: '',
    timing: 'immediate',
    frequency: null,
    delay_value: 0,
    delay_unit: 'days',
    max_sends: 1,
    is_active: true,
  }
  isDialogOpen.value = true
}

const openEdit = rule => {
  isCreating.value = false
  editingRule.value = rule
  editForm.value = {
    trigger_event: rule.trigger_event,
    template_key: rule.template_key,
    timing: rule.timing,
    frequency: rule.frequency,
    delay_value: rule.delay_value,
    delay_unit: rule.delay_unit,
    max_sends: rule.max_sends,
    is_active: rule.is_active,
  }
  isDialogOpen.value = true
}

const saveRule = async () => {
  const { valid } = await editFormRef.value.validate()
  if (!valid) return

  isSaving.value = true
  try {
    if (isCreating.value) {
      await $platformApi('/email/orchestration', {
        method: 'POST',
        body: editForm.value,
      })
      toast(t('email.ruleCreated'), 'success')
    }
    else {
      const data = await $platformApi(`/email/orchestration/${editingRule.value.id}`, {
        method: 'PUT',
        body: editForm.value,
      })

      Object.assign(editingRule.value, data.rule)
      toast(t('email.ruleSaved'), 'success')
    }
    isDialogOpen.value = false
    if (isCreating.value) await fetchAll()
  }
  catch (e) {
    toast(e.message || t('email.saveError'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

const timingDisplay = rule => {
  if (rule.timing === 'immediate') return t('email.timingImmediate')
  if (rule.timing === 'recurring') {
    const freq = frequencyOptions.find(f => f.value === rule.frequency)

    return freq ? freq.title : t('email.timingRecurring')
  }

  return `${rule.delay_value} ${t(`email.${rule.delay_unit}`)}`
}

const timingColor = timing => {
  if (timing === 'immediate') return 'success'
  if (timing === 'recurring') return 'info'

  return 'warning'
}

onMounted(fetchAll)
</script>

<template>
  <VCard>
    <VCardTitle class="d-flex align-center justify-space-between pa-5">
      <div class="d-flex align-center gap-2">
        <VIcon
          icon="tabler-robot"
          size="24"
        />
        {{ t('email.orchestrationRules') }}
      </div>
      <VBtn
        color="primary"
        prepend-icon="tabler-plus"
        @click="openCreate"
      >
        {{ t('email.createRule') }}
      </VBtn>
    </VCardTitle>

    <VCardText class="pb-0">
      <VAlert
        type="info"
        variant="tonal"
        density="compact"
        class="mb-4"
      >
        {{ t('email.orchestrationHelp') }}
      </VAlert>
    </VCardText>

    <VSkeletonLoader
      v-if="isLoading"
      type="table-heading, table-tbody"
    />

    <VTable v-else-if="rules.length">
      <thead>
        <tr>
          <th>{{ t('email.triggerEvent') }}</th>
          <th>{{ t('email.template') }}</th>
          <th>{{ t('email.timing') }}</th>
          <th>{{ t('email.maxSends') }}</th>
          <th>{{ t('email.active') }}</th>
          <th class="text-center">
            {{ t('common.actions') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="rule in rules"
          :key="rule.id"
        >
          <td>
            <VChip
              size="small"
              variant="tonal"
              color="primary"
            >
              {{ rule.trigger_event }}
            </VChip>
          </td>
          <td>
            <div class="text-body-2 font-weight-medium">
              {{ rule.template?.name || rule.template_key }}
            </div>
            <code class="text-caption text-medium-emphasis">{{ rule.template_key }}</code>
          </td>
          <td>
            <VChip
              size="small"
              variant="tonal"
              :color="timingColor(rule.timing)"
            >
              {{ timingDisplay(rule) }}
            </VChip>
          </td>
          <td class="text-body-2">
            {{ rule.max_sends === 0 ? t('email.unlimited') : rule.max_sends }}
          </td>
          <td>
            <VSwitch
              :model-value="rule.is_active"
              color="success"
              density="compact"
              hide-details
              @update:model-value="toggleRule(rule)"
            />
          </td>
          <td class="text-center">
            <VBtn
              icon
              variant="text"
              size="small"
              @click="openEdit(rule)"
            >
              <VIcon
                icon="tabler-settings"
                size="18"
              />
              <VTooltip
                activator="parent"
                location="top"
              >
                {{ t('email.editRule') }}
              </VTooltip>
            </VBtn>
          </td>
        </tr>
      </tbody>
    </VTable>

    <div
      v-else
      class="pa-12 text-center"
    >
      <VIcon
        icon="tabler-robot-off"
        size="64"
        class="text-disabled mb-4"
      />
      <p class="text-h6 text-disabled">
        {{ t('email.noRules') }}
      </p>
      <VBtn
        variant="tonal"
        prepend-icon="tabler-plus"
        @click="openCreate"
      >
        {{ t('email.createRule') }}
      </VBtn>
    </div>
  </VCard>

  <!-- Edit / Create Rule Dialog -->
  <VDialog
    v-model="isDialogOpen"
    max-width="520"
  >
    <VCard>
      <VCardTitle class="pa-5">
        {{ isCreating ? t('email.createRule') : t('email.editRule') }}
      </VCardTitle>
      <VCardText>
        <VForm
          ref="editFormRef"
          @submit.prevent="saveRule"
        >
          <!-- Trigger event (create only) -->
          <AppTextField
            v-if="isCreating"
            v-model="editForm.trigger_event"
            :label="t('email.triggerEvent')"
            :rules="[requiredValidator]"
            :hint="t('email.triggerEventHint')"
            persistent-hint
            placeholder="module.event_name"
            class="mb-4"
          />
          <div
            v-else
            class="mb-4"
          >
            <div class="text-body-2 text-medium-emphasis">
              {{ t('email.triggerEvent') }}
            </div>
            <VChip
              color="primary"
              variant="tonal"
              size="small"
            >
              {{ editForm.trigger_event }}
            </VChip>
          </div>

          <!-- Template selector -->
          <AppSelect
            v-model="editForm.template_key"
            :items="templateOptions"
            :label="t('email.template')"
            :rules="[requiredValidator]"
            class="mb-4"
          />

          <!-- Timing -->
          <AppSelect
            v-model="editForm.timing"
            :items="timingOptions"
            :label="t('email.timing')"
            class="mb-4"
          />

          <!-- Delay (when delayed) -->
          <VRow
            v-if="editForm.timing === 'delayed'"
            class="mb-4"
          >
            <VCol cols="6">
              <AppTextField
                v-model.number="editForm.delay_value"
                :label="t('email.delayValue')"
                type="number"
                :rules="[v => v >= 0 || 'Min 0']"
              />
            </VCol>
            <VCol cols="6">
              <AppSelect
                v-model="editForm.delay_unit"
                :items="delayUnitOptions"
                :label="t('email.delayUnit')"
              />
            </VCol>
          </VRow>

          <!-- Frequency (when recurring) -->
          <AppSelect
            v-if="editForm.timing === 'recurring'"
            v-model="editForm.frequency"
            :items="frequencyOptions"
            :label="t('email.frequency')"
            :rules="[requiredValidator]"
            class="mb-4"
          />

          <!-- Max sends -->
          <AppTextField
            v-model.number="editForm.max_sends"
            :label="t('email.maxSends')"
            :hint="t('email.maxSendsHint')"
            persistent-hint
            type="number"
            class="mb-4"
          />

          <!-- Active toggle -->
          <VSwitch
            v-model="editForm.is_active"
            :label="t('email.active')"
            color="success"
          />
        </VForm>
      </VCardText>
      <VCardActions class="pa-5 pt-0">
        <VSpacer />
        <VBtn
          variant="outlined"
          color="secondary"
          @click="isDialogOpen = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="isSaving"
          @click="saveRule"
        >
          {{ isCreating ? t('common.create') : t('common.save') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
