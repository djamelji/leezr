import { createApp } from 'vue'
import App from '@/App.vue'
import { registerPlugins } from '@core/utils/plugins'

// Styles
import '@core-scss/template/index.scss'
import '@styles/styles.scss'

// ─── Chunk Resilience (ADR-045d) ─────────────────────────
function handleChunkError() {
  const key = 'lzr:chunk-reload'
  const last = Number(sessionStorage.getItem(key) || 0)
  const now = Date.now()

  if (now - last > 10_000) {
    sessionStorage.setItem(key, String(now))
    window.location.reload()

    return
  }

  // 2nd failure within 10s — show update overlay
  const el = document.createElement('div')

  el.id = 'lzr-chunk-error'
  el.innerHTML = `<div style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6)">
    <div style="background:#fff;padding:2rem;border-radius:8px;text-align:center;max-width:360px">
      <h3 style="margin:0 0 .5rem">Application mise \u00e0 jour</h3>
      <p style="margin:0 0 1rem;color:#666">Une nouvelle version est disponible.</p>
      <button onclick="location.reload()" style="padding:.5rem 1.5rem;border:none;border-radius:4px;background:#7367F0;color:#fff;cursor:pointer;font-size:1rem">Rafra\u00eechir</button>
    </div>
  </div>`
  document.body.appendChild(el)
}

window.addEventListener('vite:preloadError', event => {
  event.preventDefault()
  handleChunkError()
})

window.addEventListener('unhandledrejection', event => {
  const msg = String(event.reason?.message || event.reason || '')
  if (msg.includes('Failed to fetch dynamically imported module') || msg.includes('ChunkLoadError')) {
    event.preventDefault()
    handleChunkError()
  }
})

// Create vue app
const app = createApp(App)


// Register plugins
registerPlugins(app)

// Mount vue app
app.mount('#app')
