// ADR-848 (Leezr 4.2 M9) — audit severity → chip color. Was copied in the Platform logs, the company logs tab and the
// company audit page.
export const auditSeverityColor = severity => {
  if (severity === 'critical') return 'error'
  if (severity === 'warning') return 'warning'

  return 'info'
}
