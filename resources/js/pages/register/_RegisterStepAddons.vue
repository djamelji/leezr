<script setup>
import { formatMoney } from '@/utils/money'

const { t } = useI18n()

const props = defineProps({
  addons: { type: Array, required: true },
  loading: { type: Boolean, default: false },
})

const selectedAddons = defineModel('selectedAddons', { type: Array })

const emit = defineEmits(['addonToggled'])

const toggleAddon = key => {
  const idx = selectedAddons.value.indexOf(key)

  if (idx >= 0)
    selectedAddons.value.splice(idx, 1)
  else
    selectedAddons.value.push(key)

  const addon = props.addons.find(a => a.key === key)

  emit('addonToggled', { key, action: idx >= 0 ? 'remove' : 'add', price: addon?.price || 0 })
}

const addonsTotalPrice = computed(() => {
  return props.addons
    .filter(a => selectedAddons.value.includes(a.key))
    .reduce((sum, a) => sum + a.price, 0)
})
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('register.addonsTitle') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('register.addonsDescription') }}
  </p>

  <VSkeletonLoader
    v-if="loading"
    type="card, card"
  />

  <template v-else-if="addons.length > 0">
    <VCard
      v-for="addon in addons"
      :key="addon.key"
      flat
      border
      class="mb-3 cursor-pointer"
      :class="selectedAddons.includes(addon.key) ? 'border-primary border-opacity-100' : ''"
      @click="toggleAddon(addon.key)"
    >
      <VCardText class="d-flex align-center justify-space-between">
        <div>
          <h6 class="text-h6">
            {{ addon.name }}
          </h6>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ addon.description }}
          </p>
        </div>
        <div class="d-flex align-center gap-3">
          <span class="text-body-1 font-weight-medium">
            {{ formatMoney(addon.price) }}{{ t('common.perMonth') }}
          </span>
          <VSwitch
            :model-value="selectedAddons.includes(addon.key)"
            @update:model-value="toggleAddon(addon.key)"
          />
        </div>
      </VCardText>
    </VCard>

    <div
      v-if="addonsTotalPrice > 0"
      class="text-end mt-4"
    >
      <span class="text-body-1">{{ t('register.addonsTotal') }} :</span>
      <span class="text-h6 text-primary ms-2">
        {{ formatMoney(addonsTotalPrice) }}{{ t('common.perMonth') }}
      </span>
    </div>
  </template>

  <VAlert
    v-else
    type="info"
    variant="tonal"
  >
    {{ t('register.noAddonsAvailable') }}
  </VAlert>
</template>
