<script setup>
defineProps({
  items: {
    type: Array,
    required: true,
    // Each item: { title: string, to?: RouteLocationRaw, icon?: string }
  },
})
</script>

<template>
  <VBreadcrumbs
    class="mb-4 px-0"
    :items="items.map((item, i) => ({
      title: item.title,
      to: i < items.length - 1 ? item.to : undefined,
      disabled: i === items.length - 1,
    }))"
    divider="tabler-chevron-right"
  />
</template>
