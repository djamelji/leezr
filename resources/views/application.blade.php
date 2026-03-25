@php
  $platform = \App\Platform\Models\PlatformSetting::instance();
  $appName = strtolower($platform->general['app_name'] ?? 'leezr');
  $primaryColor = $platform->theme['primary_color'] ?? '#7367F0';
  // Resolve platform typography for pre-Vue font injection (non-logged-in visitors)
  try {
      $typography = \App\Core\Typography\TypographyResolverService::forPlatform();
  } catch (\Throwable) {
      $typography = ['active_source' => null, 'active_family_name' => null, 'font_faces' => [], 'google_weights' => []];
  }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <link rel="icon" href="{{ asset('favicon.ico') }}" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ ucfirst($appName) }}</title>
  <link rel="stylesheet" type="text/css" href="{{ asset('loader.css') }}?v={{ filemtime(public_path('loader.css')) }}" />
  @vite(['resources/js/main.js'])
</head>

<body>
  <!-- ADR-342: Boot screen — persistent overlay OUTSIDE #app.
       Survives Vue mount → invisible auto-reload, no blinking.
       Removed by main.js only after full boot readiness confirmed. -->
  <div id="lzr-boot-screen">
    <div class="loading">
      <div class="effect-1 effects"></div>
      <div class="effect-2 effects"></div>
      <div class="effect-3 effects"></div>
      <span class="loading-brand">
        <span class="loading-brand-text">{{ $appName }}</span><span class="loading-brand-dot">.</span>
      </span>
    </div>
    <div class="lzr-boot-footer">
      <div class="lzr-boot-progress">
        <div class="lzr-boot-progress-bar"></div>
      </div>
      <p class="lzr-boot-status" id="lzr-boot-status"></p>
    </div>
  </div>

  <div id="app"></div>

  <script>
    // ADR-342: Show retry status on boot screen
    ;(function() {
      var status = document.getElementById('lzr-boot-status')
      // Stale/update state — show "Mise à jour en cours..."
      var staleTs = sessionStorage.getItem('lzr:stale')
      if (staleTs && Date.now() - parseInt(staleTs) < 60000) {
        if (status) status.textContent = 'Mise \u00e0 jour en cours\u2026'
      } else if (staleTs) {
        sessionStorage.removeItem('lzr:stale')
      }
      // Dev retry count
      var devRetries = sessionStorage.getItem('lzr:dev-retry')
      if (devRetries) {
        if (status) status.textContent = 'Initialisation\u2026 (' + devRetries + '/3)'
      }
    })()
  </script>

  <script>
    // ADR-329: Le préfixe localStorage correspond à namespaceConfig (themeConfig.app.title + '-')
    const loaderColor = localStorage.getItem('-initial-loader-bg') || '#FFFFFF'
    const primaryColor = localStorage.getItem('-initial-loader-color') || '{{ $primaryColor }}'

    if (loaderColor)
      document.documentElement.style.setProperty('--initial-loader-bg', loaderColor)

    if (primaryColor)
      document.documentElement.style.setProperty('--initial-loader-color', primaryColor)

    // ADR-329: Text color contrasts with background (matches BrandLogo on-surface)
    var isDarkBg = loaderColor && loaderColor.toLowerCase() !== '#ffffff' && loaderColor.toLowerCase() !== '#fff'
    document.documentElement.style.setProperty('--initial-loader-text-color', isDarkBg ? '#E1DEF5' : '#2F2B3D')

    // ADR-329 K3: Inject build version for error reporting
    window.__APP_VERSION__ = @json(config('app.build_version', 'dev'));
    // ADR-332 N1: Inject platform primary color for Vuetify fallback
    window.__PLATFORM_PRIMARY__ = '{{ $primaryColor }}';

    // ADR-341: Overlay + smart refresh — sessionStorage-based single-fire guard.
    // Uses sessionStorage instead of JS var so the guard SURVIVES page reloads.
    var __lzrOverlayFired = sessionStorage.getItem('lzr:update-shown') === 'true'

    function __lzrShowVersionOverlay() {
      // ADR-342: In dev, Vite HMR handles reconnection — no overlay needed
      if (window.__APP_VERSION__ === 'dev') {
        console.log('[lzr:version] Dev mode — skipping overlay (Vite HMR manages reconnection)')
        return
      }
      // Guard: already shown (survives reloads via sessionStorage)
      if (__lzrOverlayFired) return
      if (document.getElementById('lzr-chunk-error')) return
      __lzrOverlayFired = true
      sessionStorage.setItem('lzr:update-shown', 'true')
      sessionStorage.setItem('lzr:stale', String(Date.now()))

      var overlay = document.createElement('div')
      overlay.id = 'lzr-chunk-error'
      overlay.innerHTML = '<style>@keyframes lzr-bar{0%{transform:translateX(-100%)}100%{transform:translateX(400%)}}</style>'
        + '<div style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6)">'
        + '<div style="font-family:var(--lzr-font-family,Public Sans,sans-serif);background:var(--initial-loader-bg,#fff);padding:2rem;border-radius:8px;text-align:center;max-width:360px;min-width:300px">'
        + '<h3 style="margin:0 0 .5rem;color:var(--initial-loader-text-color,#2F2B3D)">Application mise \u00e0 jour</h3>'
        + '<div id="lzr-chunk-progress" style="display:none;width:100%;height:3px;background:rgba(128,128,128,.15);border-radius:2px;overflow:hidden;margin:1rem 0"><div style="width:25%;height:100%;background:var(--initial-loader-color,#7367F0);border-radius:2px;animation:lzr-bar 1.2s ease-in-out infinite"></div></div>'
        + '<p id="lzr-chunk-msg" style="margin:0 0 1rem;color:var(--initial-loader-text-color,#2F2B3D);opacity:.68">Une nouvelle version est disponible.</p>'
        + '<button id="lzr-chunk-btn" onclick="__lzrSmartRefresh()" style="padding:.5rem 1.5rem;border:none;border-radius:4px;background:var(--initial-loader-color,#7367F0);color:#fff;cursor:pointer;font-size:1rem">Rafra\u00eechir</button>'
        + '</div></div>'
      document.body.appendChild(overlay)
    }

    // ADR-341: Smart refresh — keep lzr:update-shown during reload.
    function __lzrSmartRefresh() {
      var btn = document.getElementById('lzr-chunk-btn')
      var msg = document.getElementById('lzr-chunk-msg')
      var progress = document.getElementById('lzr-chunk-progress')
      if (btn) { btn.disabled = true; btn.style.display = 'none' }
      if (msg) { msg.textContent = 'Mise \u00e0 jour en cours\u2026' }
      if (progress) { progress.style.display = 'block' }

      function tryReload() {
        fetch('/api/public/version', { cache: 'no-store' })
          .then(function(r) {
            if (r.ok) {
              sessionStorage.removeItem('lzr:stale')
              sessionStorage.removeItem('lzr:version-mismatch')
              location.replace(location.pathname)
            } else {
              setTimeout(tryReload, 2000)
            }
          })
          .catch(function() { setTimeout(tryReload, 2000) })
      }
      tryReload()
    }

    // ADR-342: Recurring auto-retry with backoff (replaces one-shot timer).
    // IMPORTANT: Function defined OUTSIDE if-block so boot timer & script error
    // listener can call it regardless of initial state.
    function __lzrPostRefreshRetry() {
      if (window.__lzrVueMounted) return  // Vue booted → stop
      var retryCount = parseInt(sessionStorage.getItem('lzr:retry-count') || '0', 10)
      if (retryCount >= 5) {
        // Max retries reached — give up, clear state
        sessionStorage.removeItem('lzr:update-shown')
        sessionStorage.removeItem('lzr:retry-count')
        sessionStorage.removeItem('lzr:stale')
        return
      }
      sessionStorage.setItem('lzr:retry-count', String(retryCount + 1))
      var delay = Math.min(3000 + retryCount * 2000, 10000)
      setTimeout(function() {
        if (window.__lzrVueMounted) return
        location.replace(location.pathname)
      }, delay)
    }

    if (sessionStorage.getItem('lzr:update-shown') === 'true') {
      // Show update status on boot screen
      var bootStatus = document.getElementById('lzr-boot-status')
      if (bootStatus) bootStatus.textContent = 'Mise \u00e0 jour en cours\u2026'
      // First retry after 3s (recurring via __lzrPostRefreshRetry backoff)
      window.__LZR_AUTO_RETRY__ = setTimeout(__lzrPostRefreshRetry, 3000)
    }

    // 1. Script error listener (capture phase) — détecte si main.js/chunks échouent
    window.addEventListener('error', function(e) {
      if (e.target && e.target.tagName === 'SCRIPT' && !window.__lzrVueMounted) {
        // Post-refresh: auto-retry handles recovery, no popup
        if (sessionStorage.getItem('lzr:update-shown') === 'true') return
        __lzrShowVersionOverlay()
      }
    }, true)

    // 2. Timer 10s — si le JS n'a pas monté Vue dans les 10s
    window.__LZR_BOOT_TIMER__ = setTimeout(function() {
      if (window.__lzrVueMounted) return
      // Dev: auto-reload silently (Vuetify virtual modules need a warm restart).
      // Max 3 attempts to avoid infinite loop if Vite is truly down.
      if (window.__APP_VERSION__ === 'dev') {
        var devRetries = parseInt(sessionStorage.getItem('lzr:dev-retry') || '0', 10)
        if (devRetries < 3) {
          sessionStorage.setItem('lzr:dev-retry', String(devRetries + 1))
          var status = document.getElementById('lzr-boot-status')
          if (status) status.textContent = 'Initialisation\u2026 (' + (devRetries + 1) + '/3)'
          console.warn('[lzr:boot] Dev: Vuetify modules not ready — auto-reload attempt ' + (devRetries + 1) + '/3')
          location.reload()
        } else {
          sessionStorage.removeItem('lzr:dev-retry')
          console.error('[lzr:boot] Dev: 3 reload attempts failed. Check pnpm dev:all is running.')
          var status = document.getElementById('lzr-boot-status')
          if (status) status.textContent = '\u00c9chec apr\u00e8s 3 tentatives. V\u00e9rifiez pnpm dev:all.'
        }
        return
      }
      // Prod: post-refresh retry or overlay
      if (sessionStorage.getItem('lzr:update-shown') === 'true') {
        __lzrPostRefreshRetry()
        return
      }
      __lzrShowVersionOverlay()
    }, 10000)

    // Typography early init — reads localStorage first, then falls back to
    // platform settings injected by PHP (for non-logged-in visitors).
    ;(function() {
      try {
        var raw = localStorage.getItem('lzr-typography')
        var t = raw ? JSON.parse(raw) : null

        // Fallback: platform typography from PHP (server-side resolved)
        if (!t || !t.active_family_name) {
          t = @json($typography)
        }
        if (!t || !t.active_family_name) return

        if (t.active_source === 'local' && t.font_faces) {
          var css = ''
          t.font_faces.forEach(function(f) {
            css += '@font-face { font-family: "' + t.active_family_name + '"; '
              + 'font-weight: ' + f.weight + '; font-style: ' + f.style + '; '
              + 'src: url("' + f.url + '") format("' + f.format + '"); '
              + 'font-display: swap; } '
          })
          var style = document.createElement('style')
          style.id = 'lzr-typography-preload'
          style.textContent = css
          document.head.appendChild(style)
        }

        if (t.active_source === 'google') {
          var w = (t.google_weights || [400]).join(';')
          var link = document.createElement('link')
          link.id = 'lzr-typography-google-preload'
          link.rel = 'stylesheet'
          link.href = 'https://fonts.googleapis.com/css2?family='
            + encodeURIComponent(t.active_family_name) + ':wght@' + w + '&display=swap'
          document.head.appendChild(link)
        }

        document.documentElement.style.setProperty('--lzr-font-family', '"' + t.active_family_name + '"')
      } catch(e) {}
    })()
  </script>
</body>
</html>
