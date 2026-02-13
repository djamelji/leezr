/**
 * Password strength composable — mirrors backend PasswordPolicy exactly.
 *
 * Rules match: min 8, mixedCase, numbers, symbols.
 * "uncompromised" is backend-only (breach DB check) — not simulated here.
 */
export function usePasswordStrength(password) {
  const rules = computed(() => [
    {
      label: 'At least 8 characters',
      passed: password.value.length >= 8,
    },
    {
      label: 'Uppercase letter',
      passed: /[A-Z]/.test(password.value),
    },
    {
      label: 'Lowercase letter',
      passed: /[a-z]/.test(password.value),
    },
    {
      label: 'Number',
      passed: /\d/.test(password.value),
    },
    {
      label: 'Special character',
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
