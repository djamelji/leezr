<script setup>
import QRCode from 'qrcode'
import { useTheme } from 'vuetify'

const props = defineProps({
  value: { type: String, required: true },
  size: { type: Number, default: 200 },
})

const svgContent = ref('')
const theme = useTheme()

const primaryColor = computed(() => {
  const currentTheme = theme.global.current.value
  return currentTheme.colors?.primary || '#7367F0'
})

const renderQr = async () => {
  if (!props.value) return

  svgContent.value = await QRCode.toString(props.value, {
    type: 'svg',
    width: props.size,
    margin: 2,
    color: {
      dark: primaryColor.value,
      light: '#00000000',
    },
    errorCorrectionLevel: 'M',
  })
}

onMounted(renderQr)
watch(() => props.value, renderQr)
watch(primaryColor, renderQr)
</script>

<template>
  <div
    class="app-qr-code d-inline-block"
    v-html="svgContent"
  />
</template>
