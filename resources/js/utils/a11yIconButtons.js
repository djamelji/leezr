/**
 * ADR-860 (Leezr 5.0 L7) — accessibility standard for icon-only buttons : every `<IconBtn>` and every `<VBtn icon …>`
 * carries an accessible name (`aria-label` or `title`, static or bound). Pure function over a template source, used
 * by the test that guards the harmonised pages (and by the global count of the delivery report).
 */
export function unlabeledIconButtons(source) {
  const out = []
  const re = /<(IconBtn|VBtn)\b([^>]*)>/gs
  let m
  while ((m = re.exec(source)) !== null) {
    const [whole, tag, attrs] = m
    const iconOnly = tag === 'IconBtn' || /(^|\s)(:?icon)(=|\s|$)/.test(attrs) && !/(prepend|append)-icon/.test(attrs.replace(/(^|\s):?icon=/, ''))
    if (tag === 'VBtn' && !/(^|\s):?icon(=|\s|$)/.test(attrs)) continue
    if (!iconOnly) continue
    // a menu / tooltip activator (`v-bind="props"`) gets aria-haspopup / aria-describedby, never a NAME : it needs one too
    if (/(^|\s)(:|v-bind:)?(aria-label|title)=/.test(attrs)) continue
    // ADR-861 : a decorative pictogram drawn with a button (pointer-events none) is hidden from assistive tech, not named
    if (/(^|\s)aria-hidden="true"/.test(attrs) && /tabindex="-1"/.test(attrs)) continue
    out.push({ index: m.index, line: source.slice(0, m.index).split('\n').length, tag: whole.slice(0, 80) })
  }

  return out
}
