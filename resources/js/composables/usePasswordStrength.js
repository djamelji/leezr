/**
 * Password strength composable — mirrors backend PasswordPolicy exactly.
 *
 * Rules match: min 8, mixedCase, numbers, symbols.
 * "uncompromised" is backend-only (breach DB check) — not simulated here.
 */
import { useI18n } from 'vue-i18n'

export function usePasswordStrength(password) {
  const { t } = useI18n()
  const rules = computed(() => [
    {
      label: t('auth.passwordRules.min'),
      passed: password.value.length >= 8,
    },
    {
      label: t('auth.passwordRules.upper'),
      passed: /[A-Z]/.test(password.value),
    },
    {
      label: t('auth.passwordRules.lower'),
      passed: /[a-z]/.test(password.value),
    },
    {
      label: t('auth.passwordRules.number'),
      passed: /\d/.test(password.value),
    },
    {
      label: t('auth.passwordRules.special'),
      passed: /[^A-Za-z0-9]/.test(password.value),
    },
  ])

  const strength = computed(() => rules.value.filter(r => r.passed).length)

  const color = computed(() => {
    if (strength.value <= 2)
      return 'error'
    if (strength.value <= 4)
      return 'warning'

    return 'success'
  })

  return { rules, strength, color }
}
