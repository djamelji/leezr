// ADR-834 / ADR-835 — company creation fields : the SAME rule as the server (App\Core\Fields\CompanyCreationFields),
// used by the public sign-up AND the Platform creation wizard. Fields come from the API with their effective rules
// (required, max, pattern, options, default) — inherited from the global catalog or customised by the JobDomain.

// Only a « / »-delimited pattern is a regular expression ; « email », « phone »… are rendering hints (ADR-785).
function matchesFormat(pattern, value) {
  if (typeof pattern !== 'string' || pattern.length <= 2 || pattern[0] !== '/')
    return true
  const end = pattern.lastIndexOf('/')
  try {
    return new RegExp(pattern.slice(1, end), pattern.slice(end + 1).replace(/[^imsu]/g, '')).test(value)
  }
  catch {
    return true
  }
}

const optionValues = options => (Array.isArray(options) ? options : []).map(o => (o && typeof o === 'object') ? String(o.value ?? '') : String(o))

/** Value as the server will store it : trimmed, default applied when left empty. */
const effectiveValue = (field, values) => {
  const v = String(values?.[field.code] ?? '').trim()

  return v === '' && field.default != null ? String(field.default) : v
}

/** @returns {{ missing: string[], tooLong: string[], invalid: string[] }} field labels per problem */
export function companyFieldIssues(fields = [], values = {}) {
  const out = { missing: [], tooLong: [], invalid: [] }
  for (const f of fields) {
    const v = effectiveValue(f, values)
    const options = optionValues(f.options)
    if (v === '') {
      if (f.required)
        out.missing.push(f.label)
    }
    else if (f.max && [...v].length > Number(f.max)) {
      out.tooLong.push(f.label)
    }
    else if (!matchesFormat(f.pattern, v) || (f.type === 'select' && options.length && !options.includes(v))) {
      out.invalid.push(f.label)
    }
  }

  return out
}

/** Pre-fills the default value of every field still empty (never overwrites a typed value). */
export function applyFieldDefaults(fields = [], values = {}) {
  const next = { ...values }
  for (const f of fields) {
    if (f.default != null && String(next[f.code] ?? '').trim() === '')
      next[f.code] = String(f.default)
  }

  return next
}
