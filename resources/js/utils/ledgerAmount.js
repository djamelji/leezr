// ADR-841 (Leezr 4.2 M4) — amounts served by the billing ledger datasets (revenue, refunds, outstanding, payment /
// invoice lists, MRR KPI) are INTEGER CENTS. One display rule, so no widget multiplies them by 100 again
// (the « ×100 » defect fixed in ADR-582 for two widgets and in ADR-841 for the others).
// `format` is formatMoney (cents in, localised money out) — injected to keep this module pure.
export function ledgerAmountDisplay(cents, currency, format) {
  if (cents === null || cents === undefined || Number.isNaN(Number(cents))) return '—'
  if (!currency || currency === 'MULTI') return (Number(cents) / 100).toFixed(2)

  return format(Math.round(Number(cents)), { currency })
}
