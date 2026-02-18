<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <link rel="icon" href="{{ asset('favicon.ico') }}" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Leezr</title>
  <link rel="stylesheet" type="text/css" href="{{ asset('loader.css') }}" />
  @vite(['resources/js/main.js'])
</head>

<body>
  <div id="app">
    <div id="loading-bg">
      <div class="loading-logo">
        <!-- SVG Logo -->
        <svg width="86" height="48" viewBox="0 0 34 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path fill-rule="evenodd" clip-rule="evenodd"
            d="M0.00183571 0.3125V7.59485C0.00183571 7.59485 -0.141502 9.88783 2.10473 11.8288L14.5469 23.6837L21.0172 23.6005L19.9794 10.8126L17.5261 7.93369L9.81536 0.3125H0.00183571Z"
            fill="var(--initial-loader-color)"
/>
          <path opacity="0.06" fill-rule="evenodd" clip-rule="evenodd"
            d="M8.17969 17.7762L13.3027 3.75173L17.589 8.02192L8.17969 17.7762Z" fill="#161616"
/>
          <path opacity="0.06" fill-rule="evenodd" clip-rule="evenodd"
            d="M8.58203 17.2248L14.8129 5.24231L17.6211 8.05247L8.58203 17.2248Z" fill="#161616"
/>
          <path fill-rule="evenodd" clip-rule="evenodd"
            d="M8.25781 17.6914L25.1339 0.3125H33.9991V7.62657C33.9991 7.62657 33.8144 10.0645 32.5743 11.3686L21.0179 23.6875H14.5487L8.25781 17.6914Z"
            fill="var(--initial-loader-color)"
/>
        </svg>
      </div>
      <div class=" loading">
        <div class="effect-1 effects"></div>
        <div class="effect-2 effects"></div>
        <div class="effect-3 effects"></div>
      </div>
    </div>
  </div>
  
  <script>
    const loaderColor = localStorage.getItem('Leezr-initial-loader-bg') || '#FFFFFF'
    const primaryColor = localStorage.getItem('Leezr-initial-loader-color') || '#7367F0'

    if (loaderColor)
      document.documentElement.style.setProperty('--initial-loader-bg', loaderColor)

    if (primaryColor)
      document.documentElement.style.setProperty('--initial-loader-color', primaryColor)

    // Typography early init — reads localStorage, sets CSS custom property
    // and injects @font-face / Google link before Vue mounts → no font flash.
    // The SCSS variable $font-family-custom uses var(--lzr-font-family, "Public Sans")
    // so setting the property is enough — every element picks it up naturally.
    ;(function() {
      try {
        var raw = localStorage.getItem('lzr-typography')
        if (!raw) return
        var t = JSON.parse(raw)
        if (!t.active_family_name) return

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
