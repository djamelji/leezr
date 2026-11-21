<script setup>
// ADR-835 — Platform › JobDomain › Fields : the fields asked when a company of this JobDomain is created (public
// sign-up AND Platform wizard). Each field inherits the global catalog ; the JobDomain may customise it within the
// legal and technical limits (checked by the server). Only the values that DIFFER from the inherited ones are
// stored : the others keep following the catalog.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ jobdomainId: { type: Number, required: true } })

const { t } = useI18n()
const { toast } = useAppToast()

const fields = ref([])
const markets = ref([])
const loading = ref(true)
const saving = ref(false)
const isDialogVisible = ref(false)
const editing = ref(null)
const draft = ref({ customised: false, visible: false, required: false, markets: [], default_value: '' })
const errors = ref({})

const apply = res => {
  fields.value = res.fields || []
  markets.value = res.markets || []
}

const load = async () => {
  loading.value = true
  try {
    apply(await $platformApi(`/jobdomains/${props.jobdomainId}/company-fields`))
  }
  finally {
    loading.value = false
  }
}

onMounted(load)

const marketName = key => markets.value.find(m => m.key === key)?.name || key
const marketsText = list => list == null ? t('jobdomainCompanyFields.allMarkets') : list.map(marketName).join(', ')
const yesNo = v => v ? t('common.yes') : t('common.no')

// markets a JobDomain may choose : those of the global catalog (never wider)
const marketItems = computed(() => {
  const allowed = editing.value?.catalog_markets

  return markets.value.filter(m => !allowed || allowed.includes(m.key)).map(m => ({ title: m.name, value: m.key }))
})

const isSelect = computed(() => editing.value?.type === 'select' && Array.isArray(editing.value?.options) && editing.value.options.length)
const optionItems = computed(() => (editing.value?.options || []).map(o => (o && typeof o === 'object') ? { title: o.label ?? o.value, value: o.value } : { title: o, value: o }))

const open = field => {
  const e = field.effective

  editing.value = field
  errors.value = {}
  draft.value = {
    customised: !!field.custom,
    visible: !!e.visible,
    required: !!e.required,
    markets: e.markets ? [...e.markets] : marketItems.value.map(m => m.value),
    default_value: e.default ?? '',
  }
  isDialogVisible.value = true
}

const sameSet = (a, b) => a.length === b.length && a.every(x => b.includes(x))

// only what differs from the inherited value is stored (the rest keeps following the catalog)
const payload = () => {
  const inh = editing.value.inherited
  const d = draft.value
  const inheritedMarkets = inh.markets ?? marketItems.value.map(m => m.value)
  const def = String(d.default_value ?? '').trim()

  return {
    visible: d.visible === !!inh.visible ? null : d.visible,
    required: d.required === !!inh.required ? null : d.required,
    markets: sameSet(d.markets, inheritedMarkets) ? null : d.markets,
    default_value: def === (inh.default ?? '') ? null : def,
  }
}

const save = async () => {
  saving.value = true
  errors.value = {}
  try {
    const url = `/jobdomains/${props.jobdomainId}/company-fields/${editing.value.id}`
    const body = payload()
    const inheritAll = !draft.value.customised || Object.values(body).every(v => v === null)

    apply(await $platformApi(url, inheritAll ? { method: 'DELETE' } : { method: 'PUT', body }))
    isDialogVisible.value = false
    toast(t('jobdomainCompanyFields.saved'), 'success')
  }
  catch (e) {
    errors.value = e?.data?.errors || {}
    if (!Object.keys(errors.value).length)
      toast(e?.data?.message || t('jobdomainCompanyFields.error'), 'error')
  }
  finally {
    saving.value = false
  }
}

const reset = async field => {
  saving.value = true
  try {
    apply(await $platformApi(`/jobdomains/${props.jobdomainId}/company-fields/${field.id}`, { method: 'DELETE' }))
    toast(t('jobdomainCompanyFields.resetDone'), 'success')
  }
  catch (e) {
    toast(e?.data?.message || t('jobdomainCompanyFields.error'), 'error')
  }
  finally {
    saving.value = false
  }
}

// a required field is shown (same rule as the server)
watch(() => draft.value.required, required => {
  if (required)
    draft.value.visible = true
})
watch(() => draft.value.visible, visible => {
  if (!visible)
    draft.value.required = false
})
</script>

<template>
  <VCard data-test="jd-company-fields">
    <VCardItem>
      <VCardTitle>{{ t('jobdomainCompanyFields.title') }}</VCardTitle>
      <VCardSubtitle class="text-wrap">
        {{ t('jobdomainCompanyFields.subtitle') }}
      </VCardSubtitle>
    </VCardItem>

    <VSkeletonLoader
      v-if="loading"
      type="table"
    />

    <VTable
      v-else
      class="text-no-wrap"
    >
      <thead>
        <tr>
          <th>{{ t('jobdomainCompanyFields.columns.field') }}</th>
          <th>{{ t('jobdomainCompanyFields.columns.visible') }}</th>
          <th>{{ t('jobdomainCompanyFields.columns.required') }}</th>
          <th>{{ t('jobdomainCompanyFields.columns.markets') }}</th>
          <th>{{ t('jobdomainCompanyFields.columns.default') }}</th>
          <th>{{ t('jobdomainCompanyFields.columns.origin') }}</th>
          <th class="text-end">
            {{ t('common.actions') }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="f in fields"
          :key="f.id"
          :data-test="`jd-field-${f.code}`"
        >
          <td>
            <div class="font-weight-medium">
              {{ f.label }}
            </div>
            <div class="text-caption text-disabled">
              {{ f.code }}
            </div>
          </td>
          <td>
            <VIcon
              :icon="f.effective.visible ? 'tabler-eye' : 'tabler-eye-off'"
              :color="f.effective.visible ? 'success' : 'secondary'"
              size="20"
            />
            <span class="ms-1">{{ yesNo(f.effective.visible) }}</span>
          </td>
          <td>{{ yesNo(f.effective.required) }}</td>
          <td class="text-wrap">
            {{ marketsText(f.effective.markets) }}
          </td>
          <td>{{ f.effective.default ?? '—' }}</td>
          <td>
            <VChip
              size="small"
              label
              :color="f.custom ? 'primary' : 'secondary'"
            >
              {{ f.custom ? t('jobdomainCompanyFields.customised') : t('jobdomainCompanyFields.inherited') }}
            </VChip>
            <VChip
              v-if="f.legal_required_markets.length"
              size="small"
              label
              color="warning"
              class="ms-1"
            >
              {{ t('jobdomainCompanyFields.legal', { markets: f.legal_required_markets.join(', ') }) }}
            </VChip>
          </td>
          <td class="text-end">
            <IconBtn
              :aria-label="t('common.edit')"
              :data-test="`jd-field-edit-${f.code}`"
              @click="open(f)"
            >
              <VIcon icon="tabler-pencil" />
            </IconBtn>
            <IconBtn
              v-if="f.custom"
              :aria-label="t('jobdomainCompanyFields.reset')"
              :disabled="saving"
              @click="reset(f)"
            >
              <VIcon icon="tabler-arrow-back-up" />
              <VTooltip activator="parent">
                {{ t('jobdomainCompanyFields.reset') }}
              </VTooltip>
            </IconBtn>
          </td>
        </tr>
      </tbody>
    </VTable>
  </VCard>

  <VDialog
    v-model="isDialogVisible"
    :width="$vuetify.display.smAndDown ? 'auto' : 600"
  >
    <DialogCloseBtn @click="isDialogVisible = false" />
    <VCard
      v-if="editing"
      class="pa-sm-8 pa-4"
      data-test="jd-field-dialog"
    >
      <VCardText>
        <h4 class="text-h4 mb-2">
          {{ editing.label }}
        </h4>
        <p class="text-body-2 mb-6">
          {{ t('jobdomainCompanyFields.dialogIntro') }}
        </p>

        <VAlert
          v-if="editing.legal_required_markets.length"
          type="warning"
          variant="tonal"
          class="mb-4"
        >
          {{ t('jobdomainCompanyFields.legalHint', { markets: editing.legal_required_markets.join(', ') }) }}
        </VAlert>

        <VSwitch
          v-model="draft.customised"
          :label="t('jobdomainCompanyFields.customise')"
          data-test="jd-field-customise"
          class="mb-4"
        />

        <VRow>
          <VCol
            cols="12"
            sm="6"
          >
            <VSwitch
              v-model="draft.visible"
              :disabled="!draft.customised"
              :label="t('jobdomainCompanyFields.columns.visible')"
              :hint="t('jobdomainCompanyFields.inheritedValue', { value: yesNo(editing.inherited.visible) })"
              persistent-hint
              :error-messages="errors.visible"
            />
          </VCol>
          <VCol
            cols="12"
            sm="6"
          >
            <VSwitch
              v-model="draft.required"
              :disabled="!draft.customised"
              :label="t('jobdomainCompanyFields.columns.required')"
              :hint="t('jobdomainCompanyFields.inheritedValue', { value: yesNo(editing.inherited.required) })"
              persistent-hint
              :error-messages="errors.required"
              data-test="jd-field-required"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-model="draft.markets"
              :items="marketItems"
              multiple
              chips
              closable-chips
              :disabled="!draft.customised"
              :label="t('jobdomainCompanyFields.columns.markets')"
              :hint="t('jobdomainCompanyFields.inheritedValue', { value: marketsText(editing.inherited.markets) })"
              persistent-hint
              :error-messages="errors.markets"
            />
          </VCol>
          <VCol cols="12">
            <AppSelect
              v-if="isSelect"
              v-model="draft.default_value"
              :items="optionItems"
              clearable
              :disabled="!draft.customised"
              :label="t('jobdomainCompanyFields.columns.default')"
              :hint="t('jobdomainCompanyFields.inheritedValue', { value: editing.inherited.default ?? '—' })"
              persistent-hint
              :error-messages="errors.default_value"
            />
            <AppTextField
              v-else
              v-model="draft.default_value"
              :disabled="!draft.customised"
              :maxlength="editing.max || undefined"
              :label="t('jobdomainCompanyFields.columns.default')"
              :hint="t('jobdomainCompanyFields.inheritedValue', { value: editing.inherited.default ?? '—' })"
              persistent-hint
              :error-messages="errors.default_value"
            />
          </VCol>
        </VRow>

        <div class="d-flex justify-end gap-3 mt-8">
          <VBtn
            variant="tonal"
            color="secondary"
            @click="isDialogVisible = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            :loading="saving"
            data-test="jd-field-save"
            @click="save"
          >
            {{ t('common.save') }}
          </VBtn>
        </div>
      </VCardText>
    </VCard>
  </VDialog>
</template>
