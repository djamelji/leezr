<script setup>
// Leezr 5.0 L4 (ADR-856) — recipe matrix of the Company widgets of the current user (permissioned catalog).
import LabBanner from '@/components/design-lab/LabBanner.vue'
import WidgetMatrix from '@/components/design-lab/WidgetMatrix.vue'
import { $api } from '@/utils/api'

definePage({ meta: { devOnly: true } })
</script>

<template>
  <div>
    <LabBanner name="Matrice des widgets Company (données réelles du clone)" />
    <WidgetMatrix :api-fn="$api" />
  </div>
</template>
