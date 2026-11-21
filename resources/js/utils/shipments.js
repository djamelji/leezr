// ADR-848 (Leezr 4.2 M9) — shipment status → chip color. Was copied in the shipments list, the shipment record and
// the driver delivery screen.
const SHIPMENT_STATUS_COLORS = {
  'draft': 'secondary',
  'planned': 'info',
  'in_transit': 'warning',
  'delivered': 'success',
  'canceled': 'error',
}

export const shipmentStatusColor = status => SHIPMENT_STATUS_COLORS[status] || 'secondary'
