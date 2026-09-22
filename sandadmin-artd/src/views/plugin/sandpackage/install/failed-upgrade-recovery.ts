/**
 * 失败升级恢复的局部类型与接口辅助。
 *
 * 四个冻结接口的响应一律按 unknown 收窄：核验必须绑定当前行元组，
 * 确认文本必须等于本地精确串，动态文案只走稳定码 allowlist。
 */
import sandpackageApi, { type AppInfo } from '../api/index'

/** 列表行在本页需要的候选/恢复展示字段；不改共享 API 文件。 */
export interface SandpackageInstallRow extends AppInfo {
  upgrade_candidate_verified?: boolean
  legacy_recoverable?: boolean
  derived_upgrade_from_version?: string
  recovery_mode?: FailedUpgradeRecoveryMode
  recovery_reason?: string
  ordinary_actions_blocked?: boolean
  failed_stage?: string
  candidate_archive_sha256?: string
  candidate_payload_manifest_sha256?: string
  recovery_descriptor_sha256?: string
  update_sql_sha256?: string
  failed_upgrade_replacement_id?: string
  replacement_candidate_state?: string
  /** 列表派生的允许动作；缺省表示刷新后不能进入重试。 */
  allowed_actions?: string[]
}

/** 恢复候选 ZIP 上限：5MB。 */
export const FAILED_UPGRADE_ZIP_MAX_BYTES = 5 * 1024 * 1024

/** 列表页在核验前展示的稳定人话。 */
export const FAILED_UPGRADE_FAILURE_MESSAGE =
  '数据库升级未完成，后续文件部署已停止。为避免覆盖可能的部分变更，请先核验恢复条件。'

/** blocked 状态的稳定人话；核验未通过时不得露出写操作。 */
export const FAILED_UPGRADE_BLOCKED_MESSAGE =
  '当前状态不允许自动恢复。系统没有修改插件文件或数据库，请按恢复说明处理。'

/** 核验成功后的稳定人话；不渲染后端任意 message。 */
export const FAILED_UPGRADE_VERIFIED_MESSAGE = '恢复条件检查通过。请继续检查用于恢复的插件包。'

/** 预检成功后的稳定人话。 */
export const FAILED_UPGRADE_PREPARED_MESSAGE = '插件包检查通过，确认后才会恢复插件文件。'

/** 替换成功后的稳定人话。 */
export const FAILED_UPGRADE_REPLACED_MESSAGE = '插件文件已恢复，可以重新执行升级。'

/** 重试提交后的稳定人话。 */
export const FAILED_UPGRADE_RETRIED_MESSAGE = '已重新提交升级。'

/** 运行文件已持久恢复后的固定人话；下一步仍必须重新核验。 */
export const FAILED_UPGRADE_RUNTIME_RESTORED_MESSAGE =
  '已恢复并核验升级前运行文件。请重新核验恢复条件。'

/** 恢复请求没有得到可证明的持久成功时的稳定人话。 */
export const FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE =
  '运行文件恢复结果未确认，请刷新后重新核验'

/** 核验缺字段或动作集不完整时的可恢复人话。 */
export const FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE = '恢复核验结果不完整，请刷新后重试'

/** 核验返回的应用/版本与当前行不一致时的可恢复人话。 */
export const FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE = '恢复核验结果与当前应用不一致，未打开后续步骤'

/** 确认文本与当前元组不一致时的可恢复人话。 */
export const FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE =
  '确认内容与当前应用版本不一致，请刷新后重新核验'

/** 列表加载失败时的稳定人话。 */
export const FAILED_UPGRADE_LIST_ERROR_MESSAGE = '插件列表加载失败，请刷新后重试'

/** 恢复后 presentation 只允许重新封存 ZIP 预检；不能携带替换或重试能力。 */
export const REQUIRED_VERIFY_ACTIONS = ['prepare_failed_upgrade_replacement'] as const

/** Gate A 成功后，页面只允许进入候选替换确认。 */
export const REQUIRED_REPLACE_ACTIONS = ['replace_failed_upgrade_candidate'] as const

export const REQUIRED_INSPECT_PREPARE_ACTIONS = ['prepare_failed_upgrade_replacement'] as const

export const REQUIRED_RUNTIME_RESTORE_ACTIONS = ['restore_runtime_from_backup'] as const

/** 替换后的持久列表行在步骤 3 只允许重新执行升级。 */
export const REQUIRED_DURABLE_RETRY_ACTIONS = ['retry_after_replacement'] as const

/** 列表接口可能给出的恢复展示模式。 */
export type FailedUpgradeRecoveryMode =
  | 'none'
  | 'verification_required'
  | 'runtime_restore_required'
  | 'retry_safe'
  | 'blocked'

/** 页面内部流程阶段，驱动步骤高亮与按钮显隐。 */
export type FailedUpgradeRecoveryPhase =
  | 'needs_verify'
  | 'diagnosing'
  | 'needs_prepare'
  | 'needs_gate_a'
  | 'verifying'
  | 'retry_safe'
  | 'preparing'
  | 'prepared'
  | 'replacing'
  | 'replaced'
  | 'retrying'
  | 'restoring_runtime'
  | 'blocked'
  | 'error'
  | 'success'

/** 当前行必须对齐的应用与版本元组。 */
export interface RecoveryVersionTuple {
  app: string
  fromVersion: string
  toVersion: string
}

/** 列表刷新门闩：加载中或失败时禁止恢复写操作。 */
export interface RecoveryWriteGate {
  listLoading: boolean
  listFailed: boolean
}

/** 单个失败升级应用的可刷新会话。 */
export interface FailedUpgradeRecoverySession {
  app: string
  phase: FailedUpgradeRecoveryPhase
  /** blocked 为真时只展示人话，不渲染任何写操作按钮。 */
  blocked: boolean
  fromVersion: string
  toVersion: string
  message: string
  errorMessage: string
  replacementId: string
  replaceConfirmation: string
  retryConfirmation: string
  selectedFileName: string
  profileHash: string
  runtimeDrift: boolean
  runtimeRestoreAllowed: boolean
}

/** 核验成功后页面真正使用的字段；内部 fingerprint/state 不进入界面。 */
export interface FailedUpgradeVerifyResult {
  app: string
  fromVersion: string
  toVersion: string
  message: string
  replaceConfirmation: string
  retryConfirmation: string
}

/** 预上传成功后仅保留替换编号与确认文本。 */
export interface FailedUpgradePrepareResult {
  replacementId: string
  app: string
  profileHash: string
  message: string
}

export interface FailedUpgradeInspectionResult {
  app: string
  fromVersion: string
  toVersion: string
  recoveryMode: 'verification_required' | 'runtime_restore_required'
  runtimeDrift: boolean
  message: string
}

/** 运行文件恢复只接受 Controller 明确的持久结果，不以成功文案推断。 */
export interface RuntimeRestoreResult {
  restoreId: string
  status: 'restored'
  runtimeManifestHash: string
  presentation: {
    recoveryMode: 'verification_required'
    allowedActions: ['prepare_failed_upgrade_replacement']
  }
  message: string
}

/**
 * 核验关闭错误：blocked 表示服务端明确不可重试；recoverable 保持步骤关闭并可再核验。
 */
export class FailedUpgradeRecoveryClosedError extends Error {
  readonly kind: 'blocked' | 'recoverable'

  constructor(kind: 'blocked' | 'recoverable', message: string) {
    super(message)
    this.name = 'FailedUpgradeRecoveryClosedError'
    this.kind = kind
  }
}

/** 稳定码到人话的唯一出口；未登记的码一律走兜底。 */
const STABLE_CODE_MESSAGES: Record<string, string> = {
  FAILED_UPGRADE_RECOVERY_BLOCKED: FAILED_UPGRADE_BLOCKED_MESSAGE,
  FAILED_UPGRADE_RECOVERY_RETRY_SAFE: FAILED_UPGRADE_VERIFIED_MESSAGE,
  FAILED_UPGRADE_RECOVERY_VERIFIED: FAILED_UPGRADE_VERIFIED_MESSAGE,
  FAILED_UPGRADE_RECOVERY_PREPARED: FAILED_UPGRADE_PREPARED_MESSAGE,
  FAILED_UPGRADE_RECOVERY_REPLACED: FAILED_UPGRADE_REPLACED_MESSAGE,
  FAILED_UPGRADE_RECOVERY_RETRIED: FAILED_UPGRADE_RETRIED_MESSAGE,
  FAILED_UPGRADE_RECOVERY_INCOMPLETE: FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE,
  FAILED_UPGRADE_RECOVERY_MISMATCH: FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE,
  FAILED_UPGRADE_RECOVERY_CONFIRMATION_MISMATCH: FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE,
  FAILED_UPGRADE_RECOVERY_LIST_ERROR: FAILED_UPGRADE_LIST_ERROR_MESSAGE
}

/** 允许直接展示的固定人话；不在集合内的后端字符串一律丢弃。 */
const HUMAN_MESSAGE_ALLOWLIST = new Set<string>([
  FAILED_UPGRADE_FAILURE_MESSAGE,
  FAILED_UPGRADE_BLOCKED_MESSAGE,
  FAILED_UPGRADE_VERIFIED_MESSAGE,
  FAILED_UPGRADE_PREPARED_MESSAGE,
  FAILED_UPGRADE_REPLACED_MESSAGE,
  FAILED_UPGRADE_RETRIED_MESSAGE,
  FAILED_UPGRADE_RUNTIME_RESTORED_MESSAGE,
  FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE,
  FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE,
  FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE,
  FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE,
  FAILED_UPGRADE_LIST_ERROR_MESSAGE,
  '请选择 ZIP 插件包，不能使用其他格式',
  '插件包文件无效',
  '插件包不能超过 5MB',
  '请先选择已核验的 ZIP 插件包',
  '插件包检查结果无效，请重新选择插件包',
  '未能确认已检查的插件包，请重新选择插件包',
  '插件文件恢复未完成，请重新选择插件包后再试',
  '重新执行升级未完成，请刷新后重试',
  '恢复核验结果无效，请刷新后重试'
])

const INTERNAL_DETAIL_PATTERN =
  /\b(profile|query|connection|connection_reusable|host=|port=|pdo|pg_catalog|information_schema|select\s+\*|from\s+|raw sql|update\.sql|install\.sql|uninstall\.sql|sha-?256|evidence_fingerprint|baseline_060|prefix_033|failed_stage|diagnostic_id|codex|cursor)\b/i

const FILE_PATH_PATTERN = /(?:^|[\s"'`])(?:\/|\\|[A-Za-z]:\\)[^\s]+/

const MACHINE_CODE_PATTERN = /^(?:FAILED_UPGRADE_RECOVERY|SANDPACKAGE)_[A-Z0-9_]+$/

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function readString(record: Record<string, unknown>, key: string): string | undefined {
  const value = record[key]
  return typeof value === 'string' ? value : undefined
}

function readNumber(record: Record<string, unknown>, key: string): number | undefined {
  const value = record[key]
  return typeof value === 'number' && Number.isFinite(value) ? value : undefined
}

function readBoolean(record: Record<string, unknown>, key: string): boolean | undefined {
  const value = record[key]
  return typeof value === 'boolean' ? value : undefined
}

function readStringList(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value.filter((item): item is string => typeof item === 'string')
}

function extractStableCode(raw: string): string | undefined {
  const trimmed = raw.trim()
  if (MACHINE_CODE_PATTERN.test(trimmed)) return trimmed
  const prefixed = trimmed.match(
    /^(FAILED_UPGRADE_RECOVERY_[A-Z0-9_]+|SANDPACKAGE_[A-Z0-9_]+)\s*[：:]\s*/u
  )
  if (prefixed && prefixed[1]) return prefixed[1]
  return undefined
}

function containsInternalDetail(text: string): boolean {
  return INTERNAL_DETAIL_PATTERN.test(text) || FILE_PATH_PATTERN.test(text)
}

/**
 * 只输出稳定码映射或 allowlist 人话。SQL、连接串、路径、裸机器码全部丢弃。
 */
export function humanizeRecoveryMessage(raw: string, fallback: string): string {
  const text = raw.trim()
  if (text === '') return fallback
  const code = extractStableCode(text)
  if (code && STABLE_CODE_MESSAGES[code]) return STABLE_CODE_MESSAGES[code]
  if (MACHINE_CODE_PATTERN.test(text) || extractStableCode(text)) return fallback
  if (containsInternalDetail(text)) return fallback
  if (HUMAN_MESSAGE_ALLOWLIST.has(text)) return text
  return fallback
}

/** 从 unknown 错误中取出可展示人话。 */
export function readRecoveryErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof FailedUpgradeRecoveryClosedError) {
    return humanizeRecoveryMessage(error.message, fallback)
  }
  if (error instanceof Error && error.message.trim() !== '') {
    return humanizeRecoveryMessage(error.message, fallback)
  }
  if (isPlainObject(error)) {
    const code = readString(error, 'code') ?? readString(error, 'error_code')
    if (code && STABLE_CODE_MESSAGES[code]) return STABLE_CODE_MESSAGES[code]
    const message = readString(error, 'message')
    if (message && message.trim() !== '') return humanizeRecoveryMessage(message, fallback)
  }
  return fallback
}

export function exactReplaceConfirmation(app: string, toVersion: string): string {
  return `REPLACE ${app}@${toVersion}`
}

export function exactRetryConfirmation(
  app: string,
  fromVersion: string,
  toVersion: string
): string {
  return `RETRY ${app}@${fromVersion}->${toVersion}`
}

export function exactRestoreRuntimeConfirmation(app: string, fromVersion: string): string {
  return `RESTORE RUNTIME ${app}@${fromVersion}`
}

/**
 * 确认文本必须等于当前已核验元组。后端缺省则用本地精确串；任何其它值拒绝。
 */
export function replaceConfirmationText(
  app: string,
  toVersion: string,
  backendValue?: string
): string {
  const exact = exactReplaceConfirmation(app, toVersion)
  if (!backendValue || backendValue.trim() === '') return exact
  if (backendValue.trim() === exact) return exact
  throw new FailedUpgradeRecoveryClosedError(
    'recoverable',
    FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE
  )
}

/**
 * 重试确认必须等于当前已核验 from/to。格式相近但元组不同的后端值一律拒绝。
 */
export function retryConfirmationText(
  app: string,
  fromVersion: string,
  toVersion: string,
  backendValue?: string
): string {
  const exact = exactRetryConfirmation(app, fromVersion, toVersion)
  if (!backendValue || backendValue.trim() === '') return exact
  if (backendValue.trim() === exact) return exact
  throw new FailedUpgradeRecoveryClosedError(
    'recoverable',
    FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE
  )
}

/** 只接受 ZIP，且不超过 5MB。 */
export function assertFailedUpgradeZip(file: File): string | null {
  const name = file.name.toLowerCase()
  if (!name.endsWith('.zip')) return '请选择 ZIP 插件包，不能使用其他格式'
  const allowedTypes = new Set([
    '',
    'application/zip',
    'application/x-zip-compressed',
    'application/octet-stream'
  ])
  if (!allowedTypes.has(file.type)) return '请选择 ZIP 插件包，不能使用其他格式'
  if (file.size <= 0) return '插件包文件无效'
  if (file.size > FAILED_UPGRADE_ZIP_MAX_BYTES) return '插件包不能超过 5MB'
  return null
}

/** 精确失败升级形状：state=8 / stage=failed / failed_stage=database_update / update=1。 */
export function isExactFailedUpgradeShape(row: unknown): boolean {
  if (!isPlainObject(row)) return false
  return (
    readNumber(row, 'state') === 8 &&
    readString(row, 'stage') === 'failed' &&
    readString(row, 'failed_stage') === 'database_update' &&
    readNumber(row, 'update') === 1
  )
}

/** 识别失败升级恢复行：只接受精确失败形状或明确的恢复模式。 */
export function isFailedUpgradeRecovery(row: unknown): boolean {
  if (!isPlainObject(row)) return false
  const recoveryMode = readString(row, 'recovery_mode')
  if (
    recoveryMode === 'verification_required' ||
    recoveryMode === 'runtime_restore_required' ||
    recoveryMode === 'retry_safe' ||
    recoveryMode === 'blocked'
  ) {
    return true
  }
  return isExactFailedUpgradeShape(row)
}

export function isRuntimeRestoreRequiredRow(row: unknown): boolean {
  return (
    isPlainObject(row) &&
    readString(row, 'recovery_mode') === 'runtime_restore_required' &&
    hasExactAllowedActions(readRowAllowedActions(row), ['restore_runtime_from_backup'])
  )
}

export function isBlockedRecoveryRow(row: unknown): boolean {
  if (!isPlainObject(row)) return false
  return readString(row, 'recovery_mode') === 'blocked'
}

/** 读取列表行上的允许动作；非字符串数组视为缺失。 */
export function readRowAllowedActions(row: unknown): string[] {
  if (!isPlainObject(row)) return []
  return readStringList(
    row['allowed_actions'] ?? row['allowedActions'] ?? row['recovery_allowed_actions']
  )
}

/**
 * 刷新后可进入步骤 3 的持久形状：retry_safe、精确 retry 动作、元组完整且自洽。
 */
export function isDurableRetryReadyRow(row: unknown): boolean {
  if (!isPlainObject(row) || !isFailedUpgradeRecovery(row)) return false
  if (readString(row, 'recovery_mode') !== 'retry_safe') return false
  if (!hasExactModernReplacementReadyMarkers(row)) return false
  const tuple = readRowVersionTuple(row)
  if (tuple.app === '' || tuple.fromVersion === '' || tuple.toVersion === '') return false
  const allowed = readRowAllowedActions(row)
  return hasExactAllowedActions(allowed, REQUIRED_DURABLE_RETRY_ACTIONS)
}

/** The UI independently rejects a partial or malformed modern retry marker set. */
function hasExactModernReplacementReadyMarkers(row: Record<string, unknown>): boolean {
  const digestFields = [
    'candidate_archive_sha256',
    'candidate_payload_manifest_sha256',
    'recovery_descriptor_sha256',
    'update_sql_sha256'
  ]
  if (!digestFields.every((field) => isLowerSha256(readString(row, field)))) return false
  return (
    isLowerHex(readString(row, 'failed_upgrade_replacement_id'), 32) &&
    readString(row, 'replacement_candidate_state') === 'ready'
  )
}

function isLowerSha256(value: string | undefined): boolean {
  return isLowerHex(value, 64)
}

function isLowerHex(value: string | undefined, length: number): boolean {
  return typeof value === 'string' && new RegExp(`^[a-f0-9]{${length}}$`).test(value)
}

export function readFailedUpgradeReason(row: unknown): string {
  if (!isPlainObject(row)) return FAILED_UPGRADE_FAILURE_MESSAGE
  const reason = readString(row, 'recovery_reason')
  if (reason && reason.trim() !== '') {
    return humanizeRecoveryMessage(reason, FAILED_UPGRADE_FAILURE_MESSAGE)
  }
  return FAILED_UPGRADE_FAILURE_MESSAGE
}

export function readFailedUpgradeFromVersion(row: unknown): string {
  if (!isPlainObject(row)) return ''
  return (
    readString(row, 'upgrade_from_version') ?? readString(row, 'derived_upgrade_from_version') ?? ''
  )
}

export function readRowVersionTuple(row: unknown): RecoveryVersionTuple {
  if (!isPlainObject(row)) {
    return { app: '', fromVersion: '', toVersion: '' }
  }
  return {
    app: readString(row, 'app') ?? '',
    fromVersion: readFailedUpgradeFromVersion(row),
    toVersion: readString(row, 'version') ?? ''
  }
}

export function tuplesMatch(left: RecoveryVersionTuple, right: RecoveryVersionTuple): boolean {
  return (
    left.app === right.app &&
    left.fromVersion === right.fromVersion &&
    left.toVersion === right.toVersion
  )
}

export function createFailedUpgradeSession(
  app: string,
  fromVersion: string,
  toVersion: string
): FailedUpgradeRecoverySession {
  return {
    app,
    phase: 'needs_verify',
    blocked: false,
    fromVersion,
    toVersion,
    message: FAILED_UPGRADE_FAILURE_MESSAGE,
    errorMessage: '',
    replacementId: '',
    replaceConfirmation: exactReplaceConfirmation(app, toVersion),
    retryConfirmation: exactRetryConfirmation(app, fromVersion, toVersion),
    selectedFileName: '',
    profileHash: '',
    runtimeDrift: false,
    runtimeRestoreAllowed: false
  }
}

/** 清掉核验/替换写状态，后续步骤立即关闭。 */
export function clearRecoveryWriteState(
  session: FailedUpgradeRecoverySession,
  next: {
    phase: FailedUpgradeRecoveryPhase
    blocked: boolean
    message: string
  }
): void {
  session.phase = next.phase
  session.blocked = next.blocked
  session.message = next.message
  session.errorMessage = ''
  session.replacementId = ''
  session.selectedFileName = ''
  session.profileHash = ''
  session.runtimeDrift = false
  session.runtimeRestoreAllowed = false
  session.replaceConfirmation = exactReplaceConfirmation(session.app, session.toVersion)
  session.retryConfirmation = exactRetryConfirmation(
    session.app,
    session.fromVersion,
    session.toVersion
  )
}

/**
 * 刷新后按行重算会话。步骤 3 只来自持久 retry_safe + 精确 retry 动作 + 匹配元组；
 * 缺失、不匹配、blocked 或仍含替换动作时回到核验并隐藏 retry。
 */
export function reconcileFailedUpgradeSession(
  session: FailedUpgradeRecoverySession,
  row: SandpackageInstallRow
): void {
  const tuple = readRowVersionTuple(row)
  const previous: RecoveryVersionTuple = {
    app: session.app,
    fromVersion: session.fromVersion,
    toVersion: session.toVersion
  }
  session.app = tuple.app
  session.fromVersion = tuple.fromVersion
  session.toVersion = tuple.toVersion
  if (isBlockedRecoveryRow(row)) {
    clearRecoveryWriteState(session, {
      phase: 'blocked',
      blocked: true,
      message: FAILED_UPGRADE_BLOCKED_MESSAGE
    })
    return
  }
  if (isRuntimeRestoreRequiredRow(row)) {
    clearRecoveryWriteState(session, {
      phase: 'needs_verify',
      blocked: false,
      message: FAILED_UPGRADE_FAILURE_MESSAGE
    })
    session.runtimeDrift = true
    session.runtimeRestoreAllowed = true
    return
  }
  const previousKnown =
    previous.app !== '' && previous.fromVersion !== '' && previous.toVersion !== ''
  if (isDurableRetryReadyRow(row) && (!previousKnown || tuplesMatch(previous, tuple))) {
    session.blocked = false
    session.phase = 'replaced'
    session.message = FAILED_UPGRADE_REPLACED_MESSAGE
    session.errorMessage = ''
    session.replacementId = ''
    session.selectedFileName = ''
    session.replaceConfirmation = exactReplaceConfirmation(session.app, session.toVersion)
    session.retryConfirmation = exactRetryConfirmation(
      session.app,
      session.fromVersion,
      session.toVersion
    )
    return
  }
  if (
    previousKnown &&
    tuplesMatch(previous, tuple) &&
    (session.phase === 'needs_prepare' ||
      session.phase === 'needs_gate_a' ||
      session.phase === 'retry_safe')
  ) {
    session.errorMessage = ''
    return
  }
  clearRecoveryWriteState(session, {
    phase: 'needs_verify',
    blocked: false,
    message: readFailedUpgradeReason(row)
  })
}

function hasExactAllowedActions(allowed: readonly string[], required: readonly string[]): boolean {
  return allowed.length === required.length && required.every((action) => allowed.includes(action))
}

/** v2 检查 DTO 必须绑定元组、模式和精确动作；不凭本地推断 runtime drift。 */
export function parseInspectionResult(
  payload: unknown,
  expected: RecoveryVersionTuple
): FailedUpgradeInspectionResult {
  if (!isPlainObject(payload)) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
    )
  }
  const app = readString(payload, 'app') ?? ''
  const fromVersion = readString(payload, 'from_version') ?? ''
  const toVersion = readString(payload, 'to_version') ?? ''
  const recoveryMode = readString(payload, 'recovery_mode')
  const allowed = readStringList(payload['allowed_actions'] ?? payload['allowedActions'])
  if (
    app !== expected.app ||
    fromVersion !== expected.fromVersion ||
    toVersion !== expected.toVersion
  ) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE
    )
  }
  if (recoveryMode === 'verification_required') {
    if (!hasExactAllowedActions(allowed, REQUIRED_INSPECT_PREPARE_ACTIONS)) {
      throw new FailedUpgradeRecoveryClosedError(
        'recoverable',
        FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
      )
    }
    return {
      app,
      fromVersion,
      toVersion,
      recoveryMode,
      runtimeDrift: false,
      message: FAILED_UPGRADE_FAILURE_MESSAGE
    }
  }
  if (
    recoveryMode !== 'runtime_restore_required' ||
    readBoolean(payload, 'runtime_drift') !== true ||
    !hasExactAllowedActions(allowed, REQUIRED_RUNTIME_RESTORE_ACTIONS)
  ) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
    )
  }
  return {
    app,
    fromVersion,
    toVersion,
    recoveryMode,
    runtimeDrift: true,
    message: FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE
  }
}

/**
 * Gate A 响应 fail-closed：必须绑定当前元组和已封存 replacement，且只开放替换动作。
 */
export function parseVerifyResult(
  payload: unknown,
  expected: RecoveryVersionTuple,
  replacementId: string,
  profileHash: string
): FailedUpgradeVerifyResult {
  if (!isPlainObject(payload)) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
    )
  }
  const app = readString(payload, 'app') ?? ''
  const fromVersion =
    readString(payload, 'from_version') ?? readString(payload, 'fromVersion') ?? ''
  const toVersion = readString(payload, 'to_version') ?? readString(payload, 'toVersion') ?? ''
  const verdict = readString(payload, 'verdict') ?? ''
  const returnedReplacementId =
    readString(payload, 'replacement_id') ?? readString(payload, 'replacementId') ?? ''
  const returnedProfileHash = readString(payload, 'profile_hash') ?? ''
  const evidenceFingerprint = readString(payload, 'evidence_fingerprint') ?? ''
  const assertionsTotal = readNumber(payload, 'assertions_total')
  const assertionsPassed = readNumber(payload, 'assertions_passed')
  const failedAssertionIds = readStringList(payload['failed_assertion_ids'])
  const auditWritten = readBoolean(payload, 'audit_written')
  if (app === '' || fromVersion === '' || toVersion === '' || verdict === '') {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
    )
  }
  if (
    app !== expected.app ||
    fromVersion !== expected.fromVersion ||
    toVersion !== expected.toVersion
  ) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE
    )
  }
  if (
    returnedReplacementId !== replacementId ||
    returnedProfileHash !== profileHash ||
    !isLowerHex(profileHash, 64)
  ) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE
    )
  }
  if (verdict !== 'retry_safe') {
    throw new FailedUpgradeRecoveryClosedError('blocked', FAILED_UPGRADE_BLOCKED_MESSAGE)
  }
  if (
    !isLowerSha256(evidenceFingerprint) ||
    assertionsTotal === undefined ||
    assertionsTotal < 1 ||
    assertionsPassed !== assertionsTotal ||
    failedAssertionIds.length !== 0 ||
    auditWritten !== false
  ) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
    )
  }
  const allowed = readStringList(payload['allowed_actions'] ?? payload['allowedActions'])
  if (!hasExactAllowedActions(allowed, REQUIRED_REPLACE_ACTIONS)) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
    )
  }
  return {
    app,
    fromVersion,
    toVersion,
    message: FAILED_UPGRADE_VERIFIED_MESSAGE,
    replaceConfirmation: exactReplaceConfirmation(app, toVersion),
    retryConfirmation: exactRetryConfirmation(app, fromVersion, toVersion)
  }
}

export function parsePrepareResult(
  payload: unknown,
  expected: RecoveryVersionTuple
): FailedUpgradePrepareResult {
  if (!isPlainObject(payload)) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      '插件包检查结果无效，请重新选择插件包'
    )
  }
  const replacementId =
    readString(payload, 'replacementId') ??
    readString(payload, 'replacement_id') ??
    readString(payload, 'id') ??
    ''
  if (replacementId === '') {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      '未能确认已检查的插件包，请重新选择插件包'
    )
  }
  const app = readString(payload, 'app') ?? ''
  const fromVersion = readString(payload, 'from_version') ?? ''
  const toVersion = readString(payload, 'to_version') ?? ''
  const profileHash = readString(payload, 'profile_hash') ?? ''
  if (
    app === '' ||
    app !== expected.app ||
    fromVersion !== expected.fromVersion ||
    toVersion !== expected.toVersion ||
    !isLowerHex(profileHash, 64)
  ) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE
    )
  }
  return {
    replacementId,
    app,
    profileHash,
    message: FAILED_UPGRADE_PREPARED_MESSAGE
  }
}

function parseActionMessage(payload: unknown, fallback: string): string {
  if (!isPlainObject(payload)) return fallback
  const code = readString(payload, 'code') ?? readString(payload, 'error_code')
  if (code && STABLE_CODE_MESSAGES[code]) return STABLE_CODE_MESSAGES[code]
  const message = readString(payload, 'message')
  if (message && message.trim() !== '') return humanizeRecoveryMessage(message, fallback)
  return fallback
}

/**
 * SaiAdmin's Controller response is `{ code, message, data }`. The local HTTP
 * client may already have unwrapped `data`, so accept that direct shape too.
 * No alternate success/status fields are invented here.
 */
function unwrapSaiAdminActionData(payload: unknown): unknown {
  if (!isPlainObject(payload) || !('data' in payload)) return payload
  const code = readNumber(payload, 'code')
  if (code === undefined) return payload
  if (code !== 0 && code !== 200) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE
    )
  }
  return payload['data']
}

/** A durable restore must immediately present only the next verify path. */
export function isRuntimeRestoreVerificationPresentation(
  value: unknown
): value is RuntimeRestoreResult['presentation'] {
  if (!isPlainObject(value)) return false
  return (
    readString(value, 'recovery_mode') === 'verification_required' &&
    hasExactAllowedActions(readStringList(value['allowed_actions']), REQUIRED_VERIFY_ACTIONS)
  )
}

/** Parse either the actual SaiAdmin envelope or the HTTP client's unwrapped data. */
export function parseRuntimeRestoreResult(payload: unknown): RuntimeRestoreResult {
  const data = unwrapSaiAdminActionData(payload)
  if (!isPlainObject(data)) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE
    )
  }
  const result = data['result']
  if (!isPlainObject(result)) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE
    )
  }
  const restoreId = readString(result, 'restore_id') ?? ''
  const status = readString(result, 'status')
  const runtimeManifestHash = readString(result, 'runtime_manifest_hash') ?? ''
  const presentation = data['presentation']
  if (
    !isLowerHex(restoreId, 32) ||
    status !== 'restored' ||
    !isLowerSha256(runtimeManifestHash) ||
    !isRuntimeRestoreVerificationPresentation(presentation)
  ) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE
    )
  }
  return {
    restoreId,
    status,
    runtimeManifestHash,
    presentation: {
      recoveryMode: 'verification_required',
      allowedActions: ['prepare_failed_upgrade_replacement']
    },
    message: FAILED_UPGRADE_RUNTIME_RESTORED_MESSAGE
  }
}

/** POST /app/sandpackage/install/verifyFailedUpgradeRecovery */
export async function verifyFailedUpgradeRecovery(
  expected: RecoveryVersionTuple,
  replacementId: string,
  profileHash: string
): Promise<FailedUpgradeVerifyResult> {
  const payload: unknown = await sandpackageApi.verifyFailedUpgradeRecovery({
    appName: expected.app,
    replacementId
  })
  return parseVerifyResult(payload, expected, replacementId, profileHash)
}

/** POST /app/sandpackage/install/inspectFailedUpgradeRecovery */
export async function inspectFailedUpgradeRecovery(
  expected: RecoveryVersionTuple
): Promise<FailedUpgradeInspectionResult> {
  const payload: unknown = await sandpackageApi.inspectFailedUpgradeRecovery({
    appName: expected.app
  })
  return parseInspectionResult(payload, expected)
}

/** POST /app/sandpackage/install/prepareFailedUpgradeReplacement */
export async function prepareFailedUpgradeReplacement(
  expected: RecoveryVersionTuple,
  file: File
): Promise<FailedUpgradePrepareResult> {
  const data = new FormData()
  data.append('appName', expected.app)
  data.append('file', file)
  const payload: unknown = await sandpackageApi.prepareFailedUpgradeReplacement(data)
  return parsePrepareResult(payload, expected)
}

/** POST /app/sandpackage/install/replaceFailedUpgradeCandidate */
export async function replaceFailedUpgradeCandidate(input: {
  appName: string
  replacementId: string
  confirmation: string
  expected: RecoveryVersionTuple
}): Promise<string> {
  const exact = exactReplaceConfirmation(input.expected.app, input.expected.toVersion)
  if (input.appName !== input.expected.app || input.confirmation !== exact) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE
    )
  }
  const payload: unknown = await sandpackageApi.replaceFailedUpgradeCandidate({
    appName: input.appName,
    replacementId: input.replacementId,
    confirmation: input.confirmation
  })
  return parseActionMessage(payload, FAILED_UPGRADE_REPLACED_MESSAGE)
}

/** POST /app/sandpackage/install/retryFailedUpgrade */
export async function retryFailedUpgrade(input: {
  appName: string
  confirmation: string
  expected: RecoveryVersionTuple
}): Promise<string> {
  const exact = exactRetryConfirmation(
    input.expected.app,
    input.expected.fromVersion,
    input.expected.toVersion
  )
  if (input.appName !== input.expected.app || input.confirmation !== exact) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE
    )
  }
  const payload: unknown = await sandpackageApi.retryFailedUpgrade({
    appName: input.appName,
    confirmation: input.confirmation
  })
  return parseActionMessage(payload, FAILED_UPGRADE_RETRIED_MESSAGE)
}

/** POST /app/sandpackage/install/restoreRuntimeFromBackup */
export async function restoreRuntimeFromBackup(input: {
  appName: string
  confirmation: string
  expected: RecoveryVersionTuple
}): Promise<RuntimeRestoreResult> {
  const exact = exactRestoreRuntimeConfirmation(input.expected.app, input.expected.fromVersion)
  if (input.appName !== input.expected.app || input.confirmation !== exact) {
    throw new FailedUpgradeRecoveryClosedError(
      'recoverable',
      FAILED_UPGRADE_CONFIRMATION_MISMATCH_MESSAGE
    )
  }
  const payload: unknown = await sandpackageApi.restoreRuntimeFromBackup({
    appName: input.appName,
    confirmation: input.confirmation
  })
  return parseRuntimeRestoreResult(payload)
}

/** 当前高亮步骤：诊断/预检/Gate A 归入准备，替换与重试保持独立。 */
export function recoveryStepNumber(phase: FailedUpgradeRecoveryPhase): 1 | 2 | 3 {
  if (
    phase === 'needs_prepare' ||
    phase === 'needs_gate_a' ||
    phase === 'retry_safe' ||
    phase === 'preparing' ||
    phase === 'prepared' ||
    phase === 'replacing'
  ) {
    return 2
  }
  if (phase === 'replaced' || phase === 'retrying' || phase === 'success') return 3
  return 1
}

export function isRecoveryWriteBusy(phase: FailedUpgradeRecoveryPhase): boolean {
  return (
    phase === 'diagnosing' ||
    phase === 'verifying' ||
    phase === 'preparing' ||
    phase === 'replacing' ||
    phase === 'retrying'
  )
}

/**
 * 上传预检、Gate A、替换及重试都要求可用的持久列表状态。
 */
export function canShowRecoveryWriteActions(
  session: FailedUpgradeRecoverySession,
  gate: RecoveryWriteGate
): boolean {
  if (gate.listLoading || gate.listFailed) return false
  if (session.blocked || session.phase === 'blocked' || session.phase === 'error') return false
  return (
    session.phase === 'retry_safe' ||
    session.phase === 'preparing' ||
    session.phase === 'prepared' ||
    session.phase === 'replacing' ||
    session.phase === 'replaced' ||
    session.phase === 'retrying'
  )
}

/** 核验按钮仍可在步骤 1 使用；写操作另走 canShowRecoveryWriteActions。 */
export function canShowRecoveryVerifyAction(
  session: FailedUpgradeRecoverySession,
  gate: RecoveryWriteGate
): boolean {
  if (gate.listLoading || gate.listFailed) return false
  return !isRecoveryWriteBusy(session.phase) || session.phase === 'verifying'
}

/** 任一真实失败升级恢复行存在时，隐藏页面级上传、终端等全局写动作。 */
export function shouldHideGlobalPluginWrites(
  rows: readonly SandpackageInstallRow[],
  sessions: Record<string, FailedUpgradeRecoverySession>
): boolean {
  return (
    rows.some((row) => isFailedUpgradeRecovery(row)) ||
    Object.values(sessions).some((session) => session?.blocked === true)
  )
}
