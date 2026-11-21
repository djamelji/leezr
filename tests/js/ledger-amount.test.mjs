// ADR-841 (Leezr 4.2 M4) — ledger datasets are integer cents : never × 100 again. Run : node --test tests/js/
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { ledgerAmountDisplay } from '../../resources/js/utils/ledgerAmount.js'

const fmt = (cents, { currency }) => `${(cents / 100).toFixed(2)} ${currency}` // formatMoney stand-in : cents → units

test('130 326 cents of invoiced revenue are 1 303,26 €, not 130 326 €', () => {
  assert.equal(ledgerAmountDisplay(130326, 'EUR', fmt), '1303.26 EUR')
})

test('MULTI / unknown currency : plain units, still divided by 100', () => {
  assert.equal(ledgerAmountDisplay(36283, 'MULTI', fmt), '362.83')
  assert.equal(ledgerAmountDisplay(900, null, fmt), '9.00')
})

test('missing amount → dash ; zero stays a real zero', () => {
  assert.equal(ledgerAmountDisplay(null, 'EUR', fmt), '—')
  assert.equal(ledgerAmountDisplay(undefined, 'EUR', fmt), '—')
  assert.equal(ledgerAmountDisplay(0, 'EUR', fmt), '0.00 EUR')
})

test('fractional cents from SUM() are rounded, not truncated', () => {
  assert.equal(ledgerAmountDisplay(49862.6, 'EUR', fmt), '498.63 EUR')
})
