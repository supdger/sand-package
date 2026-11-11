/**
 * 插件管理 API
 *
 * 提供插件安装、卸载、上传等功能接口
 *
 * @module api/tool/sandpackage
 */
import request from '@/utils/http'

interface RepositoryReadOptions {
  signal?: AbortSignal
  showErrorMessage?: boolean
}

export interface AppInfo {
  app: string
  title: string
  about: string
  author: string
  version: string
  support?: string
  website?: string
  state: number
  npm_dependent_wait_install?: number
  composer_dependent_wait_install?: number
  registration_candidate?: number
  update?: number
  lifecycle_driver?: string
  stage?: string
  package_backup_id?: string
  registration_manifest?: string
  runtime_manifest?: string
  upgrade_from_version?: string
  state_text?: string
  stage_label?: string
  last_error?: string
  backup_id?: string
  legacy_recoverable?: boolean
  derived_upgrade_from_version?: string
  legacy_recovery_reason?: string
  upgrade_candidate_verified?: boolean
  ordinary_actions_blocked?: boolean
  recovery_reason?: string
  cleanup_pending?: boolean
  existing_schema_available?: number
  existing_schema_attached?: number
  existing_schema_attach_pending?: number
}

/** inspectDependencies 返回的单个依赖项：服务端只读计划，前端据此编排。 */
export interface DependencyPlanEntry {
  name: string
  title?: string
  min_version: string
  installed_version: string | null
  installed_state: number | null
  action: 'satisfied' | 'install' | 'upgrade' | 'resume' | 'bundled' | 'blocked' | 'unavailable'
  reason: string
  market: { version: string; tag: string; asset: string; sha256: string } | null
}

export interface DependencyPlan {
  app: string
  dependencies: DependencyPlanEntry[]
  resolvable: boolean
  reason: string
}

export interface ExistingSchemaAttachProof {
  app: string
  version: string
  database: string
  package_sha256: string
  candidate_tree_sha256: string
  manifest_sha256: string
  identity: string
  schema: string
  ledger_rows: number
  table_count: number
  token: string
  confirmation: string
}

export interface ExistingSchemaAttachRecovery {
  app: string
  version: string
  database: string
  phase: 'not_started' | 'pending' | 'complete'
  fingerprint: string
  confirmation: string
  sql_executed: false
  restart_required: true
}

export interface VersionInfo {
  sandadmin_version?: {
    describe: string
    notes: string
    state: string
  }
  sandpackage_version?: {
    describe: string
    notes: string
    state: string
  }
}

/** 后端下发的唯一容量口径；前端不再自行定义业务上限。 */
export interface PackageLimits {
  effective_limit: number
  ceiling: number
  configured_key: string
  multipart_overhead: number
  http_max_package_size: number | null
  http_required: number
  layers: Record<string, string>
  self_check?: { describe: string; state: string; notes: string }
}

export interface AppListResponse {
  data: AppInfo[]
  version: VersionInfo
  limits?: PackageLimits | null
}

export interface FreshCandidateWithdrawal {
  app: string
  operation: 'fresh-candidate-withdrawal'
  candidate_version: string
  candidate_tree_sha256: string
  candidate_files: number
  sql_executed: false
  runtime_files_present: false
  fingerprint: string
  confirmation: string
  actions: string[]
}

export interface GlobalGuardRecord {
  record: string
  path: string
  owner: string | null
  entry: string
  orphan: boolean
  bytes: number | null
  modified: number | null
}

/** inspectUninstall 返回的单个已安装依赖方：卸载对话框据此列出警告。 */
export interface UninstallDependent {
  app: string
  title: string
  version: string | null
}

export interface UninstallInspection {
  app: string
  title: string
  version: string | null
  dependents: UninstallDependent[]
  unverifiable: UninstallDependent[]
}

export interface GlobalGuardInspection {
  app: string
  operation: 'global-guards'
  guards: GlobalGuardRecord[]
  orphan_guards: GlobalGuardRecord[]
  stale_staging: Array<{ path: string; bytes: number | null; modified: number | null }>
  blocking: boolean
  fingerprint: string
  confirmation: string
  actions: string[]
  notice: string
}

export interface RepositoryPluginVersion {
  version: string
  tag: string
  asset: string
  sha256: string
  host_min: string
  host_max?: string
  notes: string
  action: RepositoryVersionAction
  action_reason: string
}

export type RepositoryVersionAction =
  | 'install'
  | 'upgrade'
  | 'installed'
  | 'downgrade'
  | 'manage'
  | 'incompatible'

export interface RepositoryPluginLocal {
  state: number
  version: string | null
  installed_version: string | null
  candidate_sha256?: string
  blocked: boolean
  reason: string
}

export interface RepositoryPlugin {
  app: string
  repository: string
  title: string
  about: string
  author: string
  local: RepositoryPluginLocal
  versions: RepositoryPluginVersion[]
}

export interface RepositoryCatalog {
  repository: string
  ref: string
  plugins: RepositoryPlugin[]
}

export interface RepositoryDownloadRequest {
  app: string
  version: string
  sha256: string
}

export interface RepositoryDocumentResponse {
  app: string
  version: string
  markdown: string
}

/** 失败升级恢复接口的外部响应都在页面层按 unknown 收窄。 */
export interface FailedUpgradeRecoveryRequest {
  appName: string
}

export interface CleanupInspectionMenu {
  id: string
  name: string
  code: string
}

export interface CleanupInspection {
  app: string
  version: string
  tables: string[]
  menus: CleanupInspectionMenu[]
  paths: string[]
  fingerprint: string
  phase: 'ready' | 'files_pending'
  cleanup_package_version?: string
}

export interface CleanupResult {
  app: string
  state: 0
  archive: string
  restart_required: boolean
  warning?: string
}

export interface CleanupPackageResult {
  app: string
  version: string
}

export default {
  /**
   * 获取已安装的插件列表
   */
  getAppList(options: RepositoryReadOptions = {}) {
    return request.get<AppListResponse>({ url: '/app/sandpackage/install/index', ...options })
  },

  /** 读取后端下发的插件包容量口径与 HTTP 层自检结论。 */
  getPackageLimits(options: RepositoryReadOptions = {}) {
    return request.get<PackageLimits>({ url: '/app/sandpackage/install/limits', ...options })
  },

  /**
   * 上传插件包
   */
  uploadApp(data: FormData) {
    return request.post<AppInfo>({ url: '/app/sandpackage/install/upload', data })
  },

  /** 只读检查从未开始的首次安装候选能否撤回。 */
  inspectFreshCandidate(data: { appName: string }) {
    return request.post<FreshCandidateWithdrawal>({
      url: '/app/sandpackage/install/inspectFreshCandidate',
      data
    })
  },

  /** 撤回停在等待依赖的全新安装；服务端同时清掉这次写入的数据表和菜单。 */
  withdrawWaitingInstall(data: { appName: string }) {
    return request.post<{ app: string; state: number }>({
      url: '/app/sandpackage/install/withdrawWaitingInstall',
      data
    })
  },

  /** 删除从未开始的首次安装候选，释放应用名；不执行 SQL，也不动运行文件。 */
  discardFreshCandidate(data: { appName: string; confirmation: string }) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/discardFreshCandidate',
      data
    })
  },

  /** 只读检查阻塞全部插件操作的宿主守卫记录与残留上传暂存。 */
  inspectGlobalGuards(data: { appName: string }) {
    return request.post<GlobalGuardInspection>({
      url: '/app/sandpackage/install/inspectGlobalGuards',
      data
    })
  },

  /** 仅清理所属插件已不存在的孤儿守卫记录与残留上传暂存。 */
  cleanupGlobalGuards(data: { appName: string; confirmation: string }) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/cleanupGlobalGuards',
      data
    })
  },

  /**
   * 安装插件。安装含内嵌依赖插件链与宿主重载，远超 axios 默认 15s；
   * 超时后服务端仍会继续完成登记，页面按服务端状态轮询收敛。
   * restart=false 供依赖链中的依赖插件使用：不在本插件装完时重载服务，
   * 由链尾统一重载一次。
   */
  installApp(data: { appName: string; confirmation?: string; restart?: boolean }) {
    return request.post<AppInfo>({
      url: '/app/sandpackage/install/install',
      data: { ...data, restart: data.restart === false ? 0 : 1 },
      timeout: 300000
    })
  },

  /** 只读依赖计划：候选声明的依赖 → 本地状态 + 仓库最小满足版本。 */
  inspectDependencies(data: { appName: string }) {
    return request.post<DependencyPlan>({
      url: '/app/sandpackage/install/inspectDependencies',
      data,
      timeout: 65000
    })
  },

  inspectExistingSchemaAttach(data: { appName: string; database: string }) {
    return request.post<ExistingSchemaAttachProof>({
      url: '/app/sandpackage/install/inspectExistingSchemaAttach',
      data
    })
  },

  attachExistingSchema(data: { appName: string; database: string; confirmation: string }) {
    return request.post<AppInfo>({
      url: '/app/sandpackage/install/attachExistingSchema',
      data
    })
  },

  inspectExistingSchemaAttachRecovery(data: { appName: string }) {
    return request.post<ExistingSchemaAttachRecovery>({
      url: '/app/sandpackage/install/inspectExistingSchemaAttachRecovery',
      data
    })
  },

  continueExistingSchemaAttach(data: { appName: string; confirmation: string }) {
    return request.post<{ app: string; state: number; sql_executed: false; restart_required: true }>({
      url: '/app/sandpackage/install/continueExistingSchemaAttach',
      data
    })
  },

  /**
   * 登记与上传包完全一致的已部署插件，不复制文件也不执行 SQL。
   */
  registerExisting(data: { appName: string; confirmation: string }) {
    return request.post<AppInfo>({ url: '/app/sandpackage/install/registerExisting', data })
  },

  /**
   * 撤回尚未执行的升级候选；后端会再次校验备份与运行时清单。
   */
  discardCandidate(data: { appName: string; confirmation: string }) {
    return request.post<AppInfo>({ url: '/app/sandpackage/install/discardCandidate', data })
  },

  /** 只读诊断失败升级是否存在运行文件漂移。 */
  inspectFailedUpgradeRecovery(data: FailedUpgradeRecoveryRequest) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/inspectFailedUpgradeRecovery',
      data
    })
  },

  /** 封存并预检替换 ZIP；不替换候选、不执行升级。 */
  prepareFailedUpgradeReplacement(data: FormData) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/prepareFailedUpgradeReplacement',
      data
    })
  },

  /** Gate A：只读核验已封存的替换候选。 */
  verifyFailedUpgradeRecovery(data: { appName: string; replacementId: string }) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/verifyFailedUpgradeRecovery',
      data
    })
  },

  replaceFailedUpgradeCandidate(data: {
    appName: string
    replacementId: string
    confirmation: string
  }) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/replaceFailedUpgradeCandidate',
      data
    })
  },

  retryFailedUpgrade(data: { appName: string; confirmation: string }) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/retryFailedUpgrade',
      data
    })
  },

  restoreRuntimeFromBackup(data: { appName: string; confirmation: string }) {
    return request.post<unknown>({
      url: '/app/sandpackage/install/restoreRuntimeFromBackup',
      data
    })
  },

  /** 只读检查仍依赖该插件的已安装插件，卸载对话框据此列出警告。 */
  inspectUninstall(data: { appName: string }, options: RepositoryReadOptions = {}) {
    return request.post<UninstallInspection>({
      url: '/app/sandpackage/install/inspectUninstall',
      data,
      ...options
    })
  },

  /**
   * 卸载插件
   */
  uninstallApp(data: { appName: string }) {
    return request.post<unknown>({ url: '/app/sandpackage/install/uninstall', data })
  },

  /** 只读检查异常插件清理范围。 */
  inspectCleanup(data: { appName: string }) {
    return request.post<CleanupInspection>({
      url: '/tool/install/cleanup/inspect',
      data
    })
  },

  /** 保存用于补充识别残留范围的仓库包声明；不会安装插件或执行 SQL。 */
  prepareCleanupPackage(data: { app: string; version: string; sha256: string }) {
    return request.post<CleanupPackageResult>({
      url: '/tool/install/cleanup/package',
      data
    })
  },

  /** 使用本次检查凭据清理异常插件，并恢复重新安装资格。 */
  cleanupApp(data: { appName: string; fingerprint: string; confirmApp: string }) {
    return request.post<CleanupResult>({
      url: '/tool/install/cleanup',
      data
    })
  },

  /**
   * 重载后端
   */
  reloadBackend() {
    return request.post<unknown>({ url: '/app/sandpackage/install/reload' })
  },

  /** 获取服务端固定仓库的插件清单。 */
  getRepositoryCatalog(options: RepositoryReadOptions = {}) {
    return request.get<RepositoryCatalog>({
      url: '/tool/install/repository/catalog',
      timeout: 65000,
      ...options
    })
  },

  /** 下载、校验并准备插件候选；不会执行安装或升级。 */
  downloadRepositoryPlugin(data: RepositoryDownloadRequest) {
    return request.post<AppInfo>({
      url: '/tool/install/repository/download',
      data,
      timeout: 135000
    })
  },

  /** 读取与清单版本、校验和绑定的发布包文档。 */
  getRepositoryDocument(params: RepositoryDownloadRequest & { type?: 'readme' | 'changelog' }) {
    return request.get<RepositoryDocumentResponse>({
      url: '/tool/install/repository/document',
      params,
      timeout: 135000
    })
  }
}
