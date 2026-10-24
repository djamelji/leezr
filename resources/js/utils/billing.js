/**
 * ADR-341: Centralized billing status color maps.
 * Single source of truth for all invoice and subscription status colors.
 */

export const invoiceStatusColor = status => ({
  draft: 'secondary',
  open: 'warning',
  overdue: 'error',
  paid: 'success',
  void: 'secondary',   // ADR-619: real stored status is 'void' (not 'voided')
  voided: 'secondary', // back-compat alias
  written_off: 'secondary',
  uncollectible: 'error',
  uncollectible: 'error',
})[status] || 'secondary'

export const subscriptionStatusColor = status => ({
  active: 'success',
  trialing: 'info',
  past_due: 'error',
  pending_payment: 'warning',
  cancelled: 'secondary',
  suspended: 'error',
  paused: 'warning',
})[status] || 'secondary'
