/**
 * 模块边界 HTTP 替身：只替换 `@/utils/http`，供挂载真实 index.vue 的行为/视口使用。
 * 列表与恢复写接口在内存中切换，不访问网络。
 */
import type { SandpackageInstallRow } from './failed-upgrade-recovery'
import {
  FAILED_UPGRADE_BLOCKED_MESSAGE,
  FAILED_UPGRADE_FAILURE_MESSAGE,
  FAILED_UPGRADE_REPLACED_MESSAGE,
  FAILED_UPGRADE_RETRIED_MESSAGE,
  FAILED_UPGRADE_VERIFIED_MESSAGE
} from './failed-upgrade-recovery'

export type RecoveryListFixture =
  | 'verification_required'
  | 'verification_required_missing_actions'
  | 'verification_required_wrong_actions'
  | 'runtime_restore_required'
  | 'retry_ready'
  | 'mismatched_retry_ready'
  | 'partial_modern_retry_safe'
  | 'invalid_digest_retry_safe'
  | 'ordinary_blocked'
  | 'cleanup_ready'
  | 'cleanup_large'
  | 'cleanup_pending'
  | 'blocked'
  | 'missing'
  | 'error'

interface HttpRequestConfig {
  url?: string
  data?: unknown
}

function exactFailedRow(): SandpackageInstallRow {
  return {
    app: 'sample-plugin',
    title: 'Sample Plugin',
    about: 'identity service',
    author: 'sand',
    version: '0.7.0',
    state: 8,
    stage: 'failed',
    failed_stage: 'database_update',
    update: 1,
    upgrade_from_version: '0.6.0',
    ordinary_actions_blocked: true
  }
}

function exactReplacementMarkers(): Pick<
  SandpackageInstallRow,
  | 'candidate_archive_sha256'
  | 'candidate_payload_manifest_sha256'
  | 'recovery_descriptor_sha256'
  | 'update_sql_sha256'
  | 'failed_upgrade_replacement_id'
  | 'replacement_candidate_state'
> {
  return {
    candidate_archive_sha256: 'a'.repeat(64),
    candidate_payload_manifest_sha256: 'b'.repeat(64),
    recovery_descriptor_sha256: 'c'.repeat(64),
    update_sql_sha256: 'd'.repeat(64),
    failed_upgrade_replacement_id: 'e'.repeat(32),
    replacement_candidate_state: 'ready'
  }
}

function rowForFixture(
  fixture: Exclude<RecoveryListFixture, 'error' | 'missing'>
): SandpackageInstallRow {
  const row = exactFailedRow()
  if (fixture === 'ordinary_blocked') {
    row.state = 7
    row.state_text = '需要检查旧安装状态'
    row.stage = 'runtime_missing'
    row.failed_stage = undefined
    row.update = 0
    row.recovery_mode = 'none'
    row.recovery_reason = '安装记录存在，但插件运行目录缺失，请从已安装插件管理页处理'
    return row
  }
  if (fixture === 'cleanup_ready' || fixture === 'cleanup_large') {
    row.state = 7
    row.stage = 'runtime_missing'
    row.failed_stage = undefined
    row.update = 0
    row.ordinary_actions_blocked = true
    return row
  }
  if (fixture === 'cleanup_pending') {
    row.state = 8
    row.stage = 'cleanup_pending'
    row.failed_stage = undefined
    row.update = 0
    row.cleanup_pending = true
    row.ordinary_actions_blocked = true
    return row
  }
  if (fixture === 'blocked') {
    row.recovery_mode = 'blocked'
    row.recovery_reason = FAILED_UPGRADE_BLOCKED_MESSAGE
    return row
  }
  if (fixture === 'runtime_restore_required') {
    row.recovery_mode = 'runtime_restore_required'
    row.allowed_actions = ['restore_runtime_from_backup']
    return row
  }
  if (fixture === 'retry_ready') {
    Object.assign(row, exactReplacementMarkers())
    row.recovery_mode = 'retry_safe'
    row.allowed_actions = ['retry_after_replacement']
    row.recovery_reason = FAILED_UPGRADE_REPLACED_MESSAGE
    return row
  }
  if (fixture === 'mismatched_retry_ready') {
    Object.assign(row, exactReplacementMarkers())
    row.version = '0.7.1'
    row.recovery_mode = 'retry_safe'
    row.allowed_actions = ['retry_after_replacement']
    row.recovery_reason = FAILED_UPGRADE_REPLACED_MESSAGE
    return row
  }
  if (fixture === 'partial_modern_retry_safe') {
    row.failed_upgrade_replacement_id = 'e'.repeat(32)
    row.replacement_candidate_state = 'ready'
    row.recovery_mode = 'retry_safe'
    row.allowed_actions = ['retry_after_replacement']
    return row
  }
  if (fixture === 'invalid_digest_retry_safe') {
    Object.assign(row, exactReplacementMarkers())
    row.candidate_archive_sha256 = 'A'.repeat(64)
    row.recovery_mode = 'retry_safe'
    row.allowed_actions = ['retry_after_replacement']
    return row
  }
  row.recovery_mode = 'verification_required'
  if (fixture === 'verification_required_missing_actions') {
    return row
  }
  row.allowed_actions = [
    fixture === 'verification_required_wrong_actions'
      ? 'prepare_replacement'
      : 'prepare_failed_upgrade_replacement'
  ]
  return row
}

const versionInfo = {
  sandadmin_version: { describe: '6.1.0', notes: '正常', state: 'ok' },
  sandpackage_version: { describe: '0.7.0', notes: '正常', state: 'ok' }
}

let listFixture: RecoveryListFixture = 'verification_required'
type RuntimeRestoreRefreshFixture =
  | 'verification_required'
  | 'verification_required_missing_actions'
  | 'verification_required_wrong_actions'

let runtimeRestoreRefreshFixture: RuntimeRestoreRefreshFixture = 'verification_required'
let lastRecoveryPostUrl = ''
let cleanupFailureRemaining = 0
let cleanupSubmitCount = 0
let cleanupInspectCount = 0
let cleanupListCount = 0
let cleanupRepositoryCount = 0
let cleanupReloadCount = 0
let cleanupPackageCount = 0
let cleanupPackageVersion = ''

function readPostedAppName(data: unknown): string {
  if (data instanceof FormData) {
    const value = data.get('appName')
    return typeof value === 'string' ? value : ''
  }
  if (typeof data === 'object' && data !== null && !Array.isArray(data) && 'appName' in data) {
    const appName = data.appName
    return typeof appName === 'string' ? appName : ''
  }
  return ''
}

function readPostedReplacementId(data: unknown): string {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return ''
  if (!('replacementId' in data) && !('replacement_id' in data)) return ''
  const value = 'replacementId' in data ? data.replacementId : data.replacement_id
  return typeof value === 'string' ? value : ''
}

async function get(config: HttpRequestConfig): Promise<unknown> {
  const url = config.url ?? ''
  if (url.includes('/app/sandpackage/install/index')) {
    cleanupListCount += 1
    if (listFixture === 'error') {
      throw new Error('FAILED_UPGRADE_RECOVERY_LIST_ERROR')
    }
    if (listFixture === 'missing') {
      return { data: [], version: versionInfo }
    }
    return {
      data: [rowForFixture(listFixture)],
      version: versionInfo
    }
  }
  if (url.includes('/tool/install/repository/catalog')) {
    cleanupRepositoryCount += 1
    const cleaned = listFixture === 'missing' && cleanupSubmitCount > 0
    const cleanupFixture =
      listFixture === 'cleanup_ready' ||
      listFixture === 'cleanup_large' ||
      listFixture === 'cleanup_pending'
    return {
      repository: 'fixture',
      ref: 'fixture',
      plugins:
        cleanupFixture || cleaned
          ? [
              {
                app: 'sample-plugin',
                title: 'Sample Plugin',
                about: 'identity service',
                author: 'sand',
                local: {
                  state: cleaned ? 0 : listFixture === 'cleanup_pending' ? 8 : 7,
                  version: cleaned ? null : '0.7.0',
                  installed_version: null,
                  blocked: !cleaned,
                  reason: cleaned ? '' : '需要清理'
                },
                versions: ['0.7.3', '0.6.0'].map((version) => ({
                  version,
                  tag: `v${version}`,
                  asset: `sample-plugin-${version}.zip`,
                  sha256: version === '0.7.3' ? 'a'.repeat(64) : 'b'.repeat(64),
                  host_min: '6.0.0',
                  notes: 'fixture',
                  action: cleaned ? 'install' : 'manage',
                  action_reason: cleaned ? '' : '请先清理异常状态'
                }))
              },
              {
                app: 'long-title-plugin',
                title: '用于验证超长插件名称在仓库卡片中能够稳定显示两行并提供完整提示的插件',
                about:
                  '这是一段用于验证两行简介截断效果的较长说明，同时覆盖不同宽度下卡片内容对齐、操作区域换行和文字不溢出的场景。',
                author: 'a-very-long-plugin-author-name-for-responsive-layout',
                local: {
                  state: 0,
                  version: null,
                  installed_version: null,
                  blocked: false,
                  reason: ''
                },
                versions: [
                  {
                    version: '2.4.0',
                    tag: 'v2.4.0',
                    asset: 'long-title-plugin-2.4.0.zip',
                    sha256: 'c'.repeat(64),
                    host_min: '6.0.0',
                    notes: 'long content fixture',
                    action: 'install',
                    action_reason: ''
                  }
                ]
              },
              {
                app: 'workflow-helper',
                title: 'Workflow Helper',
                about: 'A compact repository card used to verify mixed content lengths.',
                author: 'sand',
                local: {
                  state: 1,
                  version: '1.2.0',
                  installed_version: '1.2.0',
                  blocked: false,
                  reason: ''
                },
                versions: [
                  {
                    version: '1.2.0',
                    tag: 'v1.2.0',
                    asset: 'workflow-helper-1.2.0.zip',
                    sha256: 'd'.repeat(64),
                    host_min: '6.0.0',
                    notes: 'installed fixture',
                    action: 'installed',
                    action_reason: '当前已安装'
                  }
                ]
              }
            ]
          : []
    }
  }
  throw new Error(`unmocked GET ${url}`)
}

async function post(config: HttpRequestConfig): Promise<unknown> {
  const url = config.url ?? ''
  lastRecoveryPostUrl = url
  const appName = readPostedAppName(config.data)
  const replacementId = readPostedReplacementId(config.data)
  if (url === '/tool/install/cleanup/inspect') {
    cleanupInspectCount += 1
    const largeInspection = listFixture === 'cleanup_large'
    return {
      app: appName,
      version: '0.7.0',
      tables:
        listFixture === 'cleanup_pending'
          ? []
          : largeInspection
            ? Array.from({ length: 86 }, (_, index) => `sand_sample_table_${index + 1}`)
            : ['sand_sample_record'],
      menus:
        listFixture === 'cleanup_pending'
          ? []
          : largeInspection
            ? Array.from({ length: 176 }, (_, index) => ({
                id: `${index + 1}`,
                name: `示例菜单 ${index + 1}`,
                code: `sample:menu:${index + 1}`
              }))
            : [{ id: '12', name: '示例插件', code: 'sample:index' }],
      paths: ['/server/plugin/sample-plugin', '/storage/sandpackage/sample-plugin.zip'],
      fingerprint: listFixture === 'cleanup_pending' ? 'files-pending-fingerprint' : 'fingerprint',
      phase: listFixture === 'cleanup_pending' ? 'files_pending' : 'ready',
      ...(cleanupPackageVersion ? { cleanup_package_version: cleanupPackageVersion } : {})
    }
  }
  if (url === '/tool/install/cleanup/package') {
    cleanupPackageCount += 1
    if (!config.data || typeof config.data !== 'object' || Array.isArray(config.data)) {
      throw new Error('invalid cleanup package request')
    }
    const version = 'version' in config.data ? config.data.version : ''
    cleanupPackageVersion = typeof version === 'string' ? version : ''
    return { app: 'sample-plugin', version: cleanupPackageVersion }
  }
  if (url === '/tool/install/cleanup') {
    cleanupSubmitCount += 1
    if (cleanupFailureRemaining > 0) {
      cleanupFailureRemaining -= 1
      listFixture = 'cleanup_pending'
      throw new Error('文件归档暂未完成')
    }
    listFixture = 'missing'
    return {
      app: appName,
      state: 0,
      archive: '/storage/sandpackage/archive/sample-plugin',
      restart_required: true,
      warning: '菜单缓存刷新失败，请检查后端日志。'
    }
  }
  if (url === '/app/sandpackage/install/reload') {
    cleanupReloadCount += 1
    return {}
  }
  if (url.includes('inspectFailedUpgradeRecovery')) {
    const runtimeRestoreRequired = listFixture === 'runtime_restore_required'
    return {
      app: appName,
      from_version: '0.6.0',
      to_version: '0.7.0',
      recovery_mode: runtimeRestoreRequired ? 'runtime_restore_required' : 'verification_required',
      allowed_actions: runtimeRestoreRequired
        ? ['restore_runtime_from_backup']
        : ['prepare_failed_upgrade_replacement'],
      ...(runtimeRestoreRequired ? { runtime_drift: true } : {}),
      message: FAILED_UPGRADE_FAILURE_MESSAGE
    }
  }
  if (url.includes('verifyFailedUpgradeRecovery')) {
    return {
      app: appName,
      from_version: '0.6.0',
      to_version: '0.7.0',
      verdict: 'retry_safe',
      replacement_id: replacementId,
      profile_hash: 'f'.repeat(64),
      evidence_fingerprint: 'e'.repeat(64),
      assertions_total: 101,
      assertions_passed: 101,
      failed_assertion_ids: [],
      audit_written: false,
      allowed_actions: ['replace_failed_upgrade_candidate'],
      message: FAILED_UPGRADE_VERIFIED_MESSAGE
    }
  }
  if (url.includes('prepareFailedUpgradeReplacement')) {
    return {
      replacement_id: 'rep-1',
      app: appName,
      from_version: '0.6.0',
      to_version: '0.7.0',
      profile_hash: 'f'.repeat(64),
      message: '插件包检查通过，确认后才会恢复插件文件。'
    }
  }
  if (url.includes('replaceFailedUpgradeCandidate')) {
    listFixture = 'retry_ready'
    return { message: FAILED_UPGRADE_REPLACED_MESSAGE }
  }
  if (url.includes('retryFailedUpgrade')) {
    listFixture = 'missing'
    return { message: FAILED_UPGRADE_RETRIED_MESSAGE }
  }
  if (url.includes('restoreRuntimeFromBackup')) {
    listFixture = runtimeRestoreRefreshFixture
    // This is the exact Controller envelope shape, intentionally without
    // client-only success/status aliases or fabricated production fields.
    return {
      code: 0,
      message: '运行文件恢复完成',
      data: {
        result: {
          restore_id: 'f'.repeat(32),
          status: 'restored',
          runtime_manifest_hash: 'a'.repeat(64)
        },
        presentation: {
          recovery_mode: 'verification_required',
          allowed_actions: ['prepare_failed_upgrade_replacement']
        }
      }
    }
  }
  throw new Error(`unmocked POST ${url}`)
}

const api = {
  get,
  post,
  put: get,
  del: get,
  request: get
}

export function configureRecoveryHttpMock(fixture: RecoveryListFixture): void {
  listFixture = fixture
  runtimeRestoreRefreshFixture = 'verification_required'
  cleanupFailureRemaining = 0
  cleanupSubmitCount = 0
  cleanupInspectCount = 0
  cleanupListCount = 0
  cleanupRepositoryCount = 0
  cleanupReloadCount = 0
  cleanupPackageCount = 0
  cleanupPackageVersion = ''
}

export function configureCleanupSubmitFailureOnce(): void {
  cleanupFailureRemaining = 1
}

export function cleanupHttpCounts(): {
  submits: number
  inspections: number
  lists: number
  repositories: number
  reloads: number
  packages: number
} {
  return {
    submits: cleanupSubmitCount,
    inspections: cleanupInspectCount,
    lists: cleanupListCount,
    repositories: cleanupRepositoryCount,
    reloads: cleanupReloadCount,
    packages: cleanupPackageCount
  }
}

/** Restore is followed by a fresh index response; keep its shape explicit in each behavior case. */
export function configureRuntimeRestoreRefreshFixture(fixture: RuntimeRestoreRefreshFixture): void {
  runtimeRestoreRefreshFixture = fixture
}

export function currentRecoveryListFixture(): RecoveryListFixture {
  return listFixture
}

export function currentRecoveryPostUrl(): string {
  return lastRecoveryPostUrl
}

export default api
