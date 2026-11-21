// ADR-848 (Leezr 4.2 M9) — mappings consolidated from copies in several pages : same results as the copies had.
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { shipmentStatusColor } from '../../resources/js/utils/shipments.js'
import { auditSeverityColor } from '../../resources/js/utils/auditSeverity.js'

test('shipment status colors (list, record, driver delivery)', () => {
  assert.equal(shipmentStatusColor('draft'), 'secondary')
  assert.equal(shipmentStatusColor('planned'), 'info')
  assert.equal(shipmentStatusColor('in_transit'), 'warning')
  assert.equal(shipmentStatusColor('delivered'), 'success')
  assert.equal(shipmentStatusColor('canceled'), 'error')
  assert.equal(shipmentStatusColor('unknown'), 'secondary')
})

test('audit severity colors (Platform logs, company logs, company audit)', () => {
  assert.equal(auditSeverityColor('critical'), 'error')
  assert.equal(auditSeverityColor('warning'), 'warning')
  assert.equal(auditSeverityColor('info'), 'info')
  assert.equal(auditSeverityColor(undefined), 'info')
})
