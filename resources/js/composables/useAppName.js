const appName = ref('Leezr')

export function useAppName() {
  return appName
}

export function setAppName(name) {
  if (name) appName.value = name
}
