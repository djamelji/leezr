import { $api } from '@/utils/api'
import { FUNNEL_STORAGE_KEY, journeyId, passedSteps, trafficSource } from '@/utils/registrationFunnel'

// ADR-849 (Leezr 4.2 M10) — public registration funnel. A random journey id (no personal data) kept in
// sessionStorage for the duration of the tab ; each step really passed is sent once (the server is idempotent per
// journey and step). Tracking never blocks nor slows the registration : every error is ignored.
export function useRegistrationFunnel() {
  const send = (step, extra = {}) => {
    const funnelId = journeyId()
    if (!funnelId) return
    $api('/register/funnel-track', { method: 'POST', body: { 'funnel_id': funnelId, step, ...extra } }).catch(() => {})
  }

  return {
    journeyId,
    started: () => send('started', { source: trafficSource(globalThis.document?.referrer) }),
    moved: (from, to) => passedSteps(from, to).forEach(s => send(s)),
    finished: () => { try { globalThis.sessionStorage?.removeItem(FUNNEL_STORAGE_KEY) } catch { /* ignore */ } },
  }
}
