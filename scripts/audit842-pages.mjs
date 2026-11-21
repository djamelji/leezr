// Leezr 4.2 audit — inventory of frontend pages (route name, path, file, definePage meta). Read-only.
import fs from 'node:fs'
import path from 'node:path'
const ROOT = 'resources/js/pages'
const typed = fs.readFileSync('typed-router.d.ts', 'utf8')
const routes = [...typed.matchAll(/'([^']+)': RouteRecordInfo<\s*'[^']+',\s*'([^']+)'/g)].map(m => ({ name: m[1], path: m[2] }))
const files = []
const walk = d => { for (const f of fs.readdirSync(d)) { const p = path.join(d, f); fs.statSync(p).isDirectory() ? walk(p) : f.endsWith('.vue') && !f.startsWith('_') && files.push(p) } }
walk(ROOT)
const toPath = f => '/' + f.slice(ROOT.length + 1).replace(/\.vue$/, '').replace(/(^|\/)index$/, '').replace(/\[\[([^\]]+)\]\]/g, ':$1?').replace(/\[\.\.\.([^\]]+)\]/g, ':$1(.*)').replace(/\[([^\]]+)\]/g, ':$1')
const pages = files.map(f => {
  const src = fs.readFileSync(f, 'utf8')
  const meta = (src.match(/definePage\(\{[\s\S]*?meta:\s*\{([\s\S]*?)\n\s*\},?\n\s*\}\)/) || [])[1] || ''
  const pick = k => (meta.match(new RegExp(`\\b${k}:\\s*([^\\n,]+(?:\\[[^\\]]*\\])?)`)) || [])[1]?.trim().replace(/,$/, '') ?? null
  const p = toPath(f)
  return {
    file: f, path: p.replace(/\/$/, '') || '/',
    scope: p.startsWith('/platform') ? 'platform' : (/^\/(login|register|forgot|reset|sign|invitations|audience|help-center|maintenance|home|referral|$)/.test(p.slice(1) ? p : '/') ? 'public/auth' : 'company'),
    layout: pick('layout'), module: pick('module'), permission: pick('permission'), anyPermission: pick('anyPermission'),
    public: pick('public'), unauthenticatedOnly: pick('unauthenticatedOnly'), redirect: /redirect:|router\.replace|navigateTo/.test(src.slice(0, 3000)),
    lines: src.split('\n').length,
  }
})
fs.writeFileSync('docs/bmad/leezr-4-2-audit/data/pages.json', JSON.stringify({ routes, pages }, null, 1))
console.log(routes.length, 'routes', pages.length, 'pages', pages.filter(p => p.scope === 'platform').length, 'platform')
