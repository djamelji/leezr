/**
 * ADR-803 — settings steps of the guided module configuration. A module declares step keys in its manifest
 * (`setup.steps`) ; each key maps to an EXISTING specialised component (no generic form, no JSON).
 * `access` is handled by the assistant itself (ModuleCapabilityAccess).
 */
const steps = {
  working_time: () => import('@/pages/company/workforce/_WorkingTimeSetup.vue'),
}

export const resolveSetupStep = key => steps[key] || null
