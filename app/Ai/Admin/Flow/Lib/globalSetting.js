export function createDefaultGlobalSettings() {
  return {
    name: '',
    code: '',
    description: '',
    status: true,
    debug: false,
    timeout_ms: 0,
    retry: {
      max_attempts: 1,
    },
    variables: [],
  }
}

export function normalizeVariables(vars) {
  if (!Array.isArray(vars))
    return []
  return vars.map((item = {}) => ({
    name: typeof item?.name === 'string' ? item.name : '',
    description: typeof item?.description === 'string' ? item.description : '',
    value: item?.value === undefined || item?.value === null ? '' : String(item.value),
  }))
}

export function normalizeGlobalSettings(settings) {
  const payload = (settings && typeof settings === 'object') ? settings : {}
  const retry = (payload.retry && typeof payload.retry === 'object') ? payload.retry : {}
  const maxAttemptsRaw = retry.max_attempts ?? retry.maxAttempts ?? 1
  const maxAttempts = Number(maxAttemptsRaw)
  const timeoutMs = Number(payload.timeout_ms ?? payload.timeoutMs ?? 0)
  return {
    name: typeof payload.name === 'string' ? payload.name : '',
    code: typeof payload.code === 'string' ? payload.code : '',
    description: typeof payload.description === 'string' ? payload.description : '',
    status: typeof payload.status === 'boolean' ? payload.status : Boolean(payload.status),
    debug: typeof payload.debug === 'boolean' ? payload.debug : Boolean(payload.debug),
    timeout_ms: Number.isFinite(timeoutMs) ? Math.max(0, Math.floor(timeoutMs)) : 0,
    retry: {
      max_attempts: Number.isFinite(maxAttempts) ? Math.max(1, Math.floor(maxAttempts)) : 1,
    },
    variables: normalizeVariables(payload.variables),
  }
}

export function resolveGlobalSettingsFromDetail(detail) {
  const base = createDefaultGlobalSettings()
  if (detail && typeof detail === 'object') {
    base.name = typeof detail.name === 'string' ? detail.name : base.name
    base.code = typeof detail.code === 'string' ? detail.code : base.code
    base.description = typeof detail.description === 'string' ? detail.description : base.description
    if (typeof detail.status !== 'undefined')
      base.status = Boolean(detail.status)
  }

  const source = detail?.global_settings ?? detail?.flow?.globalSettings ?? null
  if (!source)
    return base

  const normalized = normalizeGlobalSettings(source)
  return {
    ...base,
    debug: normalized.debug,
    timeout_ms: normalized.timeout_ms,
    retry: normalized.retry,
    variables: normalized.variables.length ? normalized.variables : base.variables,
  }
}

export function buildGlobalSettingsPayload(settings) {
  const normalized = normalizeGlobalSettings(settings)

  return {
    debug: normalized.debug,
    timeout_ms: normalized.timeout_ms,
    retry: {
      max_attempts: normalized.retry.max_attempts,
    },
    variables: normalized.variables.map(item => ({ ...item })),
  }
}
