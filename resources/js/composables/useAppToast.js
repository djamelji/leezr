import { reactive } from 'vue'

const state = reactive({
  show: false,
  message: '',
  color: 'error',
})

let timer = null

export function useAppToast() {
  function toast(message, color = 'error') {
    clearTimeout(timer)
    state.message = message
    state.color = color
    state.show = true
    timer = setTimeout(() => { state.show = false }, 4000)
  }

  return {
    state,
    toast,
  }
}
