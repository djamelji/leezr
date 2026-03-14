/**
 * ADR-341: Centralized billing status color maps.
 * Single source of truth for all invoice and subscription status colors.
 */

export const invoiceStatusColor = status => ({
  draft: 'secondary',
  open: 'warning',
  overdue: 'error',
  paid: 'success',
  voided: 'secondary',
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
