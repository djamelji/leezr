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
  <div id="app">
    <div id="loading-bg">
      <div class="loading">
        <div class="effect-1 effects"></div>
        <div class="effect-2 effects"></div>
        <div class="effect-3 effects"></div>
        <span class="loading-brand">
          <span class="loading-brand-text">{{ $appName }}</span><span class="loading-brand-dot">.</span>
        </span>
      </div>
      <!-- ADR-330: During smart refresh, show "mise à jour" message below the spinner -->
      <div id="loading-update-msg" style="display:none;text-align:center;margin-block-start:1.5rem;font-family:var(--lzr-font-family,Public Sans,sans-serif)">
        <p style="margin:0;color:var(--initial-loader-text-color,#2F2B3D);opacity:.68;font-size:.875rem">Mise à jour en cours…</p>
      </div>
    </div>
  </div>
  <script>
    // ADR-330c: Show update message if app was marked stale (TTL 60s)
    var __staleTs = sessionStorage.getItem('lzr:stale')
    if (__staleTs && Date.now() - parseInt(__staleTs) < 60000) {
      var updateMsg = document.getElementById('loading-update-msg');
      if (updateMsg) updateMsg.style.display = 'block';
    } else if (__staleTs) {
      sessionStorage.removeItem('lzr:stale')
    }
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
    // This is the ROOT FIX for the "5 popups in 3 minutes" reload loop.
    var __lzrOverlayFired = sessionStorage.getItem('lzr:update-shown') === 'true'

    function __lzrShowVersionOverlay() {
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
    // The flag STAYS set so the new page load is PROTECTED against chunk errors
    // that fire before Vue boots. Only main.js cleanup (after successful boot)
    // clears the flag — proving the new assets actually loaded.
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
              // Only clear stale + mismatch. Keep lzr:update-shown as guard.
              // main.js cleanup will clear it after Vue boots successfully.
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

    // ADR-341: If page loads with lzr:update-shown (post-refresh but Vue hasn't booted yet),
    // auto-retry reload every 3s instead of showing another popup. The user already clicked
    // "Rafraîchir" once — no need to ask again, just keep trying silently.
    if (sessionStorage.getItem('lzr:update-shown') === 'true') {
      var __lzrAutoRetryTimer = setTimeout(function() {
        // Only auto-retry if Vue HASN'T booted (loading-bg still visible)
        if (!document.getElementById('loading-bg')) return
        fetch('/api/public/version', { cache: 'no-store' })
          .then(function(r) { if (r.ok) location.replace(location.pathname) })
          .catch(function() {})
      }, 3000)
      // Cancel if Vue boots successfully
      window.__LZR_AUTO_RETRY__ = __lzrAutoRetryTimer
    }

    // 1. Script error listener (capture phase) — détecte immédiatement si main.js/chunks échouent
    window.addEventListener('error', function(e) {
      if (e.target && e.target.tagName === 'SCRIPT' && document.getElementById('loading-bg')) {
        __lzrShowVersionOverlay()
      }
    }, true)

    // 2. Timer 10s — si le JS n'a pas monté Vue dans les 10s
    window.__LZR_BOOT_TIMER__ = setTimeout(function() {
      if (!document.getElementById('loading-bg')) return
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
