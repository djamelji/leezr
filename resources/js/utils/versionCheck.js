/**
 * ADR-260: Pre-flight version check for login pages.
 *
 * On mount, fires a HEAD request to /api/health to read X-Build-Version.
 * If the server version differs from the baked-in client version,
 * triggers an immediate page reload to pick up the new JS bundles.
 *
 * This prevents stale-JS login failures after a deploy.
 */
export function checkVersionOnMount() {
  const clientVersion = import.meta.env.VITE_APP_VERSION
  if (!clientVersion || clientVersion === '__dev__') return

  onMounted(async () => {
    try {
      const res = await fetch('/health', { method: 'HEAD', cache: 'no-store' })
      const serverVersion = res.headers.get('x-build-version')

      if (serverVersion && serverVersion !== 'dev' && serverVersion !== clientVersion) {
        window.location.reload()
      }
    }
    catch {
      // Network error — skip check, login will work or fail on its own
    }
  })
}
