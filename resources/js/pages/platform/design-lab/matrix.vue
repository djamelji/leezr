<script setup>
// Leezr 5.0 L4 (ADR-856) — recipe matrix of the Platform widgets (real component, real data, sizes, states).
import LabBanner from '@/components/design-lab/LabBanner.vue'
import WidgetMatrix from '@/components/design-lab/WidgetMatrix.vue'
import { $platformApi } from '@/utils/platformApi'

definePage({ meta: { layout: 'platform', platform: true, permission: 'view_dashboard', devOnly: true } })
</script>

<template>
  <div>
    <LabBanner name="Matrice des widgets Platform (données réelles du clone)" />
    <WidgetMatrix
      :api-fn="$platformApi"
      explicit-scope
    />
  </div>
</template>
