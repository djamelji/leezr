/**
 * ADR-803 — settings steps of the guided module configuration. A module declares step keys in its manifest
 * (`setup.steps`) ; each key maps to an EXISTING specialised component (no generic form, no JSON).
 * `access` is handled by the assistant itself (ModuleCapabilityAccess).
 */
const steps = {
  working_time: () => import('@/pages/company/workforce/_WorkingTimeSetup.vue'),
  leave_types: () => import('@/pages/company/workforce/leave/_LeaveTypesSetupStep.vue'), // ADR-804
  contract_templates: () => import('@/pages/company/documents/_ContractTemplatesSetupStep.vue'), // ADR-805
  signature_settings: () => import('@/pages/company/workforce/signature/_SignatureSettingsSetupStep.vue'), // ADR-805
}

export const resolveSetupStep = key => steps[key] || null
