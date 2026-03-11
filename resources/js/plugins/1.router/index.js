import { setupLayouts } from 'virtual:meta-layouts'
import { createRouter, createWebHistory } from 'vue-router/auto'
import { redirects, routes } from './additional-routes'
import { setupGuards } from './guards'

function recursiveLayouts(route) {
  if (route.children) {
    for (let i = 0; i < route.children.length; i++)
      route.children[i] = recursiveLayouts(route.children[i])
    
    return route
  }
  
  return setupLayouts([route])[0]
}

const router = createRouter({
  history: createWebHistory('/'),
  scrollBehavior(to) {
    if (to.hash)
      return { el: to.hash, behavior: 'smooth', top: 60 }
    
    return { top: 0 }
  },
  extendRoutes: pages => [
    ...redirects,
    ...[
      ...pages,
      ...routes,
    ].map(route => recursiveLayouts(route)),
  ],
})

setupGuards(router)

// ADR-046 F3 + ADR-330b: Catch chunk load failures during navigation.
// Delegates to Blade's centralized overlay instead of doing a blind reload.
// A direct reload() here bypasses all guards (_chunkErrorHandled, __lzrOverlayFired,
// grace period) and resets JS-memory flags → causes reload loops and multiple popups.
router.onError(error => {
  const msg = String(error?.message || '')
  if (msg.includes('Loading chunk') || msg.includes('ChunkLoadError') || msg.includes('Failed to fetch dynamically imported module')) {
    try {
      const payload = JSON.stringify({
        type: 'chunk_load_failure',
        message: msg.slice(0, 2000),
        url: window.location.href,
        timestamp: new Date().toISOString(),
        build_version: window.__APP_VERSION__ || null,
      })

      if (navigator.sendBeacon) {
        navigator.sendBeacon('/api/runtime-error', new Blob([payload], { type: 'application/json' }))
      }
    }
    catch {
      // Fire-and-forget
    }

    if (typeof window.__lzrShowVersionOverlay === 'function') {
      window.__lzrShowVersionOverlay()
    }
    else {
      location.replace(location.pathname)
    }
  }
})

export { router }
export default function (app) {
  app.use(router)
}
