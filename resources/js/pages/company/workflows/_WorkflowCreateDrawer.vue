<script setup>
import AppStepper from '@core/components/AppStepper.vue'
import AppDrawerHeaderSection from '@core/components/AppDrawerHeaderSection.vue'
import { useWorkflowsStore } from '@/modules/company/workflows/workflows.store'

const props = defineProps({
  isDrawerOpen: { type: Boolean, required: true },
  triggers: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:isDrawerOpen', 'created'])

const { t } = useI18n()
const store = useWorkflowsStore()

const currentStep = ref(0)
const formRef = ref(null)

const stepperItems = computed(() => [
  { title: t('workflows.stepTrigger'), subtitle: t('workflows.stepTriggerDesc') },
  { title: t('workflows.stepConditions'), subtitle: t('workflows.stepConditionsDesc') },
  { title: t('workflows.stepActions'), subtitle: t('workflows.stepActionsDesc') },
])

const defaultForm = () => ({
  name: '',
  trigger_topic: '',
  conditions: [],
  actions: [{ type: 'send_notification', config: {} }],
  enabled: true,
  max_executions_per_day: 100,
  cooldown_minutes: 0,
})

const form = ref(defaultForm())

const triggerOptions = computed(() =>
  Object.entries(props.triggers).map(([topic, meta]) => ({
    title: meta.label,
    value: topic,
  })),
)

const selectedTrigger = computed(() =>
  props.triggers[form.value.trigger_topic] || null,
)

const conditionOperators = [
  { title: '=', value: '=' },
  { title: '!=', value: '!=' },
  { title: '>', value: '>' },
  { title: '<', value: '<' },
  { title: t('workflows.opContains'), value: 'contains' },
  { title: t('workflows.opStartsWith'), value: 'starts_with' },
  { title: t('workflows.opIn'), value: 'in' },
]

const actionTypes = computed(() => {
  if (!selectedTrigger.value) return []

  return (selectedTrigger.value.actions || []).map(a => ({
    title: t(`workflows.action_${a}`),
    value: a,
  }))
})

const conditionFields = computed(() => {
  if (!selectedTrigger.value) return []

  return (selectedTrigger.value.conditions || []).map(c => ({
    title: c.label || c.key,
    value: c.key,
  }))
})

const addCondition = () => {
  form.value.conditions.push({ field: '', operator: '=', value: '' })
}

const removeCondition = idx => {
  form.value.conditions.splice(idx, 1)
}

const addAction = () => {
  form.value.actions.push({ type: 'send_notification', config: {} })
}

const removeAction = idx => {
  form.value.actions.splice(idx, 1)
}

const canProceed = computed(() => {
  if (currentStep.value === 0) {
    return form.value.name.trim() && form.value.trigger_topic
  }

  // Step 1 (conditions) — always valid (conditions are optional)
  if (currentStep.value === 1) return true

  // Step 2 (actions) — at least one action with a type
  if (currentStep.value === 2) {
    return form.value.actions.length > 0 && form.value.actions.every(a => a.type)
  }

  return true
})

const onSubmit = async () => {
  try {
    const payload = {
      ...form.value,
      conditions: form.value.conditions.filter(c => c.field && c.operator),
    }

    await store.createRule(payload)
    close()
    emit('created')
  }
  catch {
    // Error handled by store
  }
}

const close = () => {
  emit('update:isDrawerOpen', false)
  currentStep.value = 0
  form.value = defaultForm()
}
</script>

<template>
  <VNavigationDrawer
    :model-value="isDrawerOpen"
    temporary
    location="end"
    width="600"
    class="scrollable-content"
    @update:model-value="val => !val && close()"
  >
    <AppDrawerHeaderSection
      :title="t('workflows.createTitle')"
      @cancel="close"
    />

    <VDivider />

    <div class="pa-6">
      <!-- Stepper -->
      <AppStepper
        :items="stepperItems"
        :current-step="currentStep"
        :is-active-step-valid="canProceed"
        class="mb-6"
      />

      <!-- Step 1: Trigger -->
      <div v-show="currentStep === 0">
        <VRow>
          <VCol cols="12">
            <AppTextField
              v-model="form.name"
              :label="t('workflows.name')"
              :placeholder="t('workflows.namePlaceholder')"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="form.trigger_topic"
              :items="triggerOptions"
              :label="t('workflows.triggerSelect')"
            />
          </VCol>
          <VCol
            cols="6"
          >
            <AppTextField
              v-model.number="form.max_executions_per_day"
              :label="t('workflows.maxPerDay')"
              type="number"
            />
          </VCol>
          <VCol
            cols="6"
          >
            <AppTextField
              v-model.number="form.cooldown_minutes"
              :label="t('workflows.cooldown')"
              type="number"
            />
          </VCol>
          <VCol cols="12">
            <VSwitch
              v-model="form.enabled"
              :label="t('workflows.enabledLabel')"
              color="success"
            />
          </VCol>
        </VRow>
      </div>

      <!-- Step 2: Conditions -->
      <div v-show="currentStep === 1">
        <p class="text-body-2 text-medium-emphasis mb-4">
          {{ t('workflows.conditionsHelp') }}
        </p>

        <div
          v-for="(cond, idx) in form.conditions"
          :key="idx"
          class="mb-3"
        >
          <VRow>
            <VCol cols="4">
              <AppSelect
                v-model="cond.field"
                :items="conditionFields"
                :label="t('workflows.field')"
                density="compact"
              />
            </VCol>
            <VCol cols="3">
              <AppSelect
                v-model="cond.operator"
                :items="conditionOperators"
                :label="t('workflows.operator')"
                density="compact"
              />
            </VCol>
            <VCol cols="4">
              <AppTextField
                v-model="cond.value"
                :label="t('workflows.value')"
                density="compact"
              />
            </VCol>
            <VCol
              cols="1"
              class="d-flex align-center"
            >
              <IconBtn
                color="error"
                size="small"
                @click="removeCondition(idx)"
              >
                <VIcon icon="tabler-x" />
              </IconBtn>
            </VCol>
          </VRow>
        </div>

        <VBtn
          v-if="conditionFields.length > 0"
          variant="tonal"
          size="small"
          prepend-icon="tabler-plus"
          @click="addCondition"
        >
          {{ t('workflows.addCondition') }}
        </VBtn>

        <VAlert
          v-if="conditionFields.length === 0 && form.trigger_topic"
          type="info"
          variant="tonal"
          class="mt-2"
        >
          {{ t('workflows.noConditionsAvailable') }}
        </VAlert>

        <VAlert
          v-if="!form.trigger_topic"
          type="warning"
          variant="tonal"
          class="mt-2"
        >
          {{ t('workflows.selectTriggerFirst') }}
        </VAlert>
      </div>

      <!-- Step 3: Actions -->
      <div v-show="currentStep === 2">
        <div
          v-for="(action, idx) in form.actions"
          :key="idx"
          class="mb-3"
        >
          <VRow>
            <VCol cols="5">
              <AppSelect
                v-model="action.type"
                :items="actionTypes"
                :label="t('workflows.actionType')"
                density="compact"
              />
            </VCol>
            <VCol cols="6">
              <AppTextField
                v-if="action.type === 'webhook'"
                v-model="action.config.url"
                :label="t('workflows.webhookUrl')"
                placeholder="https://..."
                density="compact"
              />
              <span
                v-else
                class="text-body-2 text-medium-emphasis"
              >
                {{ t(`workflows.actionDesc_${action.type}`) }}
              </span>
            </VCol>
            <VCol
              cols="1"
              class="d-flex align-center"
            >
              <IconBtn
                v-if="form.actions.length > 1"
                color="error"
                size="small"
                @click="removeAction(idx)"
              >
                <VIcon icon="tabler-x" />
              </IconBtn>
            </VCol>
          </VRow>
        </div>

        <VBtn
          variant="tonal"
          size="small"
          prepend-icon="tabler-plus"
          @click="addAction"
        >
          {{ t('workflows.addAction') }}
        </VBtn>
      </div>

      <VDivider class="my-6" />

      <!-- Navigation buttons -->
      <div class="d-flex justify-space-between">
        <VBtn
          v-if="currentStep > 0"
          variant="tonal"
          @click="currentStep--"
        >
          {{ t('common.previous') }}
        </VBtn>
        <VSpacer />
        <VBtn
          v-if="currentStep < 2"
          color="primary"
          :disabled="!canProceed"
          @click="currentStep++"
        >
          {{ t('common.next') }}
        </VBtn>
        <VBtn
          v-else
          color="primary"
          :loading="store.loading.saving"
          :disabled="!canProceed"
          @click="onSubmit"
        >
          {{ t('workflows.createBtn') }}
        </VBtn>
      </div>
    </div>
  </VNavigationDrawer>
</template>
