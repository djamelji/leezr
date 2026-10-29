<script setup>
// W-G1 (ADR-641) — champ de saisie monétaire. L'utilisateur saisit un montant lisible
// ("2 500,00 €"), le v-model expose TOUJOURS des centimes entiers (unité attendue par l'API).
// Conversion par arithmétique entière (jamais float × 100) → aucune perte de précision.
import { parseMoneyToCents, centsToInput, currencySymbol } from '@/utils/money'
import { useWorldStore } from '@/core/stores/world'

const props = defineProps({
  modelValue: { type: Number, default: null }, // centimes entiers
  label: { type: String, default: '' },
  hint: { type: String, default: '' },
  currency: { type: String, default: '' }, // ISO ; défaut = contexte Company/Market (world store)
  persistentHint: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  clearable: { type: Boolean, default: false },
  density: { type: String, default: 'comfortable' },
  rules: { type: Array, default: () => [] },
  allowNegative: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const { t, locale } = useI18n()
const world = useWorldStore()

const effectiveCurrency = computed(() => props.currency || world.currency)
const symbol = computed(() => currencySymbol({ currency: effectiveCurrency.value, locale: locale.value }))

const text = ref(centsToInput(props.modelValue, { locale: locale.value }))

// Sync entrant : si le parent change les centimes et que ça ne correspond pas à la saisie
// courante, on réécrit le champ (sans écraser la frappe en cours).
watch(() => props.modelValue, val => {
  if (parseMoneyToCents(text.value) !== val)
    text.value = centsToInput(val, { locale: locale.value })
})

const onInput = val => {
  text.value = val
  emit('update:modelValue', parseMoneyToCents(val))
}

// Normalisation à la perte de focus (2 décimales propres).
const onBlur = () => {
  const cents = parseMoneyToCents(text.value)
  text.value = cents === null ? '' : centsToInput(cents, { locale: locale.value })
}

const validRule = v => {
  const raw = String(v ?? '').trim()
  if (raw === '') return true
  const cents = parseMoneyToCents(raw)
  if (cents === null) return t('validation.money.invalid')
  if (!props.allowNegative && cents < 0) return t('validation.money.negative')

  return true
}

const allRules = computed(() => [validRule, ...props.rules])
</script>

<template>
  <AppTextField
    :model-value="text"
    :label="label"
    :hint="hint"
    :persistent-hint="persistentHint"
    :disabled="disabled"
    :clearable="clearable"
    :density="density"
    :rules="allRules"
    inputmode="decimal"
    :suffix="symbol"
    @update:model-value="onInput"
    @blur="onBlur"
  />
</template>
