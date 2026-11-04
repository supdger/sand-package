/**
 * 一键安装依赖链的纯判定与编排核心（不依附组件生命周期）。
 *
 * 依赖任务只允许经认证管理员会话的 SSE 终端启动（TerminalRunner::isSuperAdmin），
 * 后端没有不经会话的执行器，因此自动依赖链只能由页面触发。这里的判定以服务端
 * 登记状态（info.ini 的 state、operation_pending 与 *_dependent_wait_install
 * 标志）为唯一依据，不依赖安装请求的客户端成败：axios 超时或连接断开时，
 * 服务端 PHP 仍在后台继续完成安装登记，按服务端状态轮询收敛才不会让链静默缺失。
 * 依赖等待标志在锁内先于事务提交对外可见（deployFreshOrUpgrade 先登记
 * state=4+等待标志再写载荷），operation_pending=1 期间入队必撞安装操作锁，
 * 裁决按在途继续轮询；服务端把残余锁冲突映射为【锁冲突】记号供瞬态重试。
 *
 * 0.2.18 把「安装 → 轮询 → 依赖链」挂在安装视图组件内：安装完成触发
 * 动态路由重建后 keep-alive 停用视图，组件内 readsActive/getList 轮询
 * 在停用窗口静默空转，依赖链从未入队（WP-D 复现）。本模块把编排收敛
 * 为依赖注入的纯函数（runInstallOrchestration / runDependencyChainLoop），
 * 读通道、安装请求与依赖任务入队全部由调用方提供，由应用级 pinia 编排器
 * （../store/install-orchestrator）持有——视图停用、重激活或路由重建都
 * 不影响链推进；视图只渲染编排器暴露的状态。
 *
 * 本模块不引用 Vue / axios / 宿主别名，供 node:test 直接覆盖。
 */

export interface DependencyChainRow {
  app: string
  state?: number
  version?: string
  composer_dependent_wait_install?: number
  npm_dependent_wait_install?: number
  operation_pending?: number
  cleanup_pending?: boolean
  ordinary_actions_blocked?: boolean
  state_text?: string
  stage_label?: string
  last_error?: string
  recovery_reason?: string
}

export type DependencyKind = 'composer' | 'npm'

export type InstallOutcomeStatus = 'wait_dependencies' | 'installed' | 'failed' | 'pending'

export interface InstallOutcome {
  status: InstallOutcomeStatus
  row: DependencyChainRow | null
}

/** 服务端登记的剩余依赖任务类型；composer 先于 npm，与页面链序一致。 */
export const dependencyKindOf = (row: DependencyChainRow | null | undefined): DependencyKind | null => {
  if (!row) return null
  if (row.composer_dependent_wait_install === 1) return 'composer'
  if (row.npm_dependent_wait_install === 1) return 'npm'
  return null
}

/**
 * 服务端登记的安装结果判定：
 * - state 3/5/6/7/8/0 或未知异常态 → 安装失败或需人工处理（先判：
 *   安装失败现场保留 operation_pending=1，不能按「在途」吞掉）
 * - operation_pending=1 → 安装/操作事务仍在锁内：依赖等待标志在锁内
 *   先于事务提交对外可见（deployFreshOrUpgrade 先于载荷写入登记
 *   state=4+*_dependent_wait_install，载荷写入触发整页 reload），
 *   此时入队必撞安装操作锁——按「在途」继续轮询，待事务提交解锁
 * - 依赖等待标志 → 可立即入队依赖任务
 * - state=1 → 安装完成
 * - 其余（安装进行中的 state=2/4、行暂缺）→ 仍需等待
 */
export const installOutcomeOf = (
  row: DependencyChainRow | null | undefined
): InstallOutcomeStatus => {
  if (!row) return 'pending'
  const state = row.state
  if (state === 0 || state === 3 || (typeof state === 'number' && state >= 5)) return 'failed'
  if (row.operation_pending === 1) return 'pending'
  if (dependencyKindOf(row) !== null) return 'wait_dependencies'
  if (state === 1) return 'installed'
  if (state === 2 || state === 4) return 'pending'
  return 'failed'
}

/** 失败行内最有可读性的原因字段，按后端可解释性优先排序。 */
export const installOutcomeReason = (
  row: DependencyChainRow | null | undefined,
  fallback = '安装未完成，请到插件管理查看'
): string => {
  const reason = row?.recovery_reason || row?.last_error || row?.stage_label || row?.state_text
  return reason && reason !== '' ? reason : fallback
}

/**
 * 安装请求的客户端结果无法确定（axios 超时、断网、连接被重置或被本地取消）
 * 时，服务端 PHP 通常仍在继续安装；此类错误不应当作安装失败处理，也不能据
 * 此跳过依赖链——改由轮询服务端登记状态裁决。业务拒绝（HTTP 4xx 或响应体
 * code!=200）经响应拦截器抛出的 HttpError 没有 transport 标记，不在此列。
 */
export const isUncertainInstallError = (error: unknown): boolean => {
  if (!error || typeof error !== 'object') return false
  const transport = (error as { transport?: unknown }).transport
  return (
    transport === 'timeout' ||
    transport === 'network' ||
    transport === 'unknown' ||
    transport === 'cancelled'
  )
}

export const INSTALL_OUTCOME_POLL_BUDGET_MS = 180000
export const INSTALL_OUTCOME_POLL_INTERVAL_MS = 2000

export interface PollInstallOutcomeOptions {
  /** 插件标识。 */
  app: string
  /** 触发一次服务端状态刷新（例如 getList）。内部已吞错时允许失败。 */
  refreshRows: () => Promise<void>
  /** 读取当前行快照。 */
  readRows: () => DependencyChainRow[]
  /** 测试可注入的睡眠实现。 */
  sleep?: (ms: number) => Promise<void>
  /** 总轮询预算（毫秒）。 */
  budgetMs?: number
  /** 单次轮询间隔（毫秒）。 */
  intervalMs?: number
  /** 测试可注入的时钟。 */
  now?: () => number
}

const defaultSleep = (ms: number): Promise<void> =>
  new Promise((resolve) => {
    setTimeout(resolve, ms)
  })

/**
 * 轮询服务端登记状态，直到出现依赖等待、已安装、失败或预算耗尽。
 * 预算耗尽返回 timeout 并附最后观测行，由调用方提示人工查看，
 * 绝不把未确认状态当作安装成功。
 */
export const pollInstallOutcome = async (
  options: PollInstallOutcomeOptions
): Promise<InstallOutcome> => {
  const budgetMs = options.budgetMs ?? INSTALL_OUTCOME_POLL_BUDGET_MS
  const intervalMs = options.intervalMs ?? INSTALL_OUTCOME_POLL_INTERVAL_MS
  const sleep = options.sleep ?? defaultSleep
  const now = options.now ?? Date.now
  const deadline = now() + Math.max(intervalMs, budgetMs)
  let row: DependencyChainRow | undefined
  let status: InstallOutcomeStatus = 'pending'
  while (true) {
    await options.refreshRows()
    row = options.readRows().find((item) => item.app === options.app)
    status = installOutcomeOf(row)
    if (status !== 'pending') break
    if (now() + intervalMs > deadline) break
    await sleep(intervalMs)
  }
  if (status === 'pending') {
    return { status: 'pending', row: row ?? null }
  }
  return { status, row: row ?? null }
}

// ========== 编排核心：安装 → 轮询 → 依赖链 ==========
// 以下函数由应用级 pinia 编排器（store/install-orchestrator）调用；
// 调用方注入读通道、安装请求与依赖任务入队，全部不依附组件生命周期。

/** 依赖链失败时暴露给终端面板的通俗失败说明。 */
export interface InstallChainFailure {
  app: string
  title: string
  /** 失败环节的人类可读标签（如「后端依赖（composer）」）。 */
  label: string
  /** 终端输出归纳出的通俗原因。 */
  reason: string
}

/**
 * 编排收敛结果。调用方（视图/编排器 store）按 kind 做呈现：
 * - installed：安装完成且依赖链跑完（或无依赖待装）
 * - install-failed：服务端登记安装失败/需人工处理
 * - chain-failed：依赖任务失败；failure 供终端面板弹出与一键重试
 * - unconfirmed：预算耗尽仍无法确认安装结果，提示到插件管理查看
 */
export type InstallOrchestrationOutcome =
  | { kind: 'installed' }
  | { kind: 'install-failed'; reason: string; row: DependencyChainRow | null }
  | { kind: 'chain-failed'; failure: InstallChainFailure }
  | { kind: 'unconfirmed'; reason: string; row: DependencyChainRow | null }

export interface InstallOrchestrationInput {
  app: string
  title: string
  /** 升级确认串（install 走 UPGRADE 确认时传）。 */
  confirmation?: string
}

export interface DependencyOrchestratorDeps {
  /**
   * 发起安装请求并返回服务端登记行（AppInfo 形状）。依赖任务重试等
   * 不需要安装请求的调用方可不提供。
   */
  install?: (app: string, confirmation?: string) => Promise<DependencyChainRow | null | undefined>
  /**
   * 读取服务端登记行快照。这是编排器的自有读通道：实现不得挂在组件
   * 生命周期的 readsActive/取消令牌上；读失败可保留上次快照返回，链
   * 不因此中断。
   */
  fetchRows: () => Promise<DependencyChainRow[]>
  /**
   * 入队一个依赖任务并等待其终态；成功返回 true。实现侧走应用级
   * 终端任务队列（store 持有 fetch-SSE，不依附视图）。
   */
  runDependencyTask: (kind: DependencyKind, app: string) => Promise<boolean>
  /** 依赖任务失败后的通俗原因读取（通常为终端输出尾部归纳）。 */
  failureReason?: (app: string, kind: DependencyKind) => string
  /**
   * 依赖任务失败是否为「安装操作锁冲突」瞬态：服务端把 flock 冲突
   * （OperationLockException）映射为失败详情中的稳定记号
   * 【锁冲突】（DependencyCommandSupport::LOCK_CONFLICT_TOKEN），
   * 实现侧按终端失败任务输出判定。返回 true 时编排器在
   * lockWaitBudgetMs 预算内重试入队同一环节，而非判链失败。
   */
  lockConflict?: (app: string, kind: DependencyKind) => boolean
  /**
   * 编排阶段迁移回调：安装请求发出后置 'install'，开始入队依赖链前置
   * 'chain'。应用级编排器用它更新 sessionStorage 断点记录，整页 reload
   * 后续跑时按服务端行快照裁决，本字段仅作诊断信息。
   */
  onPhase?: (phase: InstallOrchestrationPhase) => void
  /** 测试可注入的睡眠/时钟/轮询预算与链步数上限。 */
  sleep?: (ms: number) => Promise<void>
  now?: () => number
  pollBudgetMs?: number
  pollIntervalMs?: number
  maxChainSteps?: number
  /**
   * 安装事务在途等待与依赖入队锁冲突重试共享的总预算（毫秒），
   * 默认与轮询预算相同。wait 标志在锁内先于事务提交对外可见，
   * 撞锁属瞬态，预算内等待/重试而非直接判失败。
   */
  lockWaitBudgetMs?: number
}

/**
 * 服务端依赖命令失败详情中的锁冲突分类记号，与
 * DependencyCommandSupport::LOCK_CONFLICT_TOKEN 保持一致；
 * 前端只按该记号判定瞬态，不裸匹配中文错误文案。
 */
export const DEPENDENCY_LOCK_CONFLICT_TOKEN = '【锁冲突】'

export const dependencyTaskLabel = (kind: DependencyKind): string =>
  kind === 'composer' ? '后端依赖（composer）' : '前端依赖（npm/pnpm）'

const chainFailed = (
  app: string,
  title: string,
  label: string,
  reason: string
): InstallOrchestrationOutcome => ({ kind: 'chain-failed', failure: { app, title, label, reason } })

/**
 * 依次执行该插件剩余的依赖任务。每步先刷新服务端快照，由 info.ini
 * 登记的 *_dependent_wait_install 标志决定下一步；initialRows 允许
 * 调用方传入已有快照作为第一步失败读的兜底。读失败保留上次快照继续，
 * 不中断链——是否完成只认服务端登记。
 *
 * 与安装事务在途期解耦（WP-PRE2 撞锁返工）：
 * - operation_pending=1：安装/操作事务仍在锁内，等待标志先于解锁
 *   对外可见，此时入队必撞操作锁——在 lockWaitBudgetMs 预算内按
 *   pollIntervalMs 节奏等待事务提交；登记转入失败态立即收尾；
 * - 依赖任务仍被操作锁拒绝（服务端【锁冲突】记号，如行快照与解锁
 *   之间的残余窗口或其他插件的在途事务）：同一预算内重试入队同一
 *   环节，不消耗链步数；预算耗尽或非锁类失败才判 chain-failed。
 */
export const runDependencyChainLoop = async (
  deps: Pick<
    DependencyOrchestratorDeps,
    | 'fetchRows'
    | 'runDependencyTask'
    | 'failureReason'
    | 'lockConflict'
    | 'maxChainSteps'
    | 'sleep'
    | 'now'
    | 'pollIntervalMs'
    | 'lockWaitBudgetMs'
  >,
  app: string,
  title: string,
  initialRows: DependencyChainRow[] = []
): Promise<InstallOrchestrationOutcome> => {
  const maxSteps = Math.max(1, deps.maxChainSteps ?? 8)
  const intervalMs = deps.pollIntervalMs ?? INSTALL_OUTCOME_POLL_INTERVAL_MS
  const sleep = deps.sleep ?? defaultSleep
  const now = deps.now ?? Date.now
  const lockDeadline = now() + Math.max(
    intervalMs,
    deps.lockWaitBudgetMs ?? INSTALL_OUTCOME_POLL_BUDGET_MS
  )
  let rows = initialRows
  let step = 0
  while (step < maxSteps) {
    try {
      rows = await deps.fetchRows()
    } catch {
      // 读失败保留上次快照；轮次上限与失败兜底不变。
    }
    let row = rows.find((item) => item.app === app)
    if (!row) {
      return chainFailed(app, title, '依赖', '无法读取插件当前状态，请刷新后重试')
    }
    // 安装/操作事务仍在锁内：等待标志先于解锁对外可见，此时入队必撞
    // 操作锁——预算内按轮询节奏等待事务提交后再入队。
    while (row.operation_pending === 1) {
      if (installOutcomeOf(row) === 'failed') {
        return chainFailed(
          app,
          title,
          '依赖',
          installOutcomeReason(row, '安装未完成，请到插件管理查看')
        )
      }
      if (now() + intervalMs > lockDeadline) {
        return chainFailed(app, title, '依赖', '仍有安装操作正在执行，请稍后到插件管理重试')
      }
      await sleep(intervalMs)
      try {
        rows = await deps.fetchRows()
      } catch {
        // 同上：读失败保留上次快照，预算到期即收尾。
      }
      const next = rows.find((item) => item.app === app)
      if (!next) {
        return chainFailed(app, title, '依赖', '无法读取插件当前状态，请刷新后重试')
      }
      row = next
    }
    const kind = dependencyKindOf(row)
    if (kind === null) return { kind: 'installed' }
    const ok = await deps.runDependencyTask(kind, app)
    if (ok) {
      step += 1
      continue
    }
    // 瞬态锁冲突（服务端【锁冲突】记号）：预算内重试同一环节。
    if (deps.lockConflict?.(app, kind) === true && now() + intervalMs <= lockDeadline) {
      await sleep(intervalMs)
      continue
    }
    return chainFailed(
      app,
      title,
      dependencyTaskLabel(kind),
      deps.failureReason?.(app, kind) || '请查看终端输出中的具体错误'
    )
  }
  return chainFailed(app, title, '依赖', '依赖任务轮次异常，请到插件管理确认插件状态')
}

/** 轮询服务端登记并按行快照裁决收尾：依赖等待→入队依赖链，其余→终态。 */
const settleInstallBySnapshot = async (
  deps: DependencyOrchestratorDeps,
  input: InstallOrchestrationInput,
  initialRows: DependencyChainRow[]
): Promise<InstallOrchestrationOutcome> => {
  let rows = initialRows
  const outcome = await pollInstallOutcome({
    app: input.app,
    refreshRows: async () => {
      try {
        rows = await deps.fetchRows()
      } catch {
        // 读失败保留上次快照，由轮询预算兜底，不把未确认当成功。
      }
    },
    readRows: () => rows,
    sleep: deps.sleep,
    now: deps.now,
    budgetMs: deps.pollBudgetMs,
    intervalMs: deps.pollIntervalMs
  })

  if (outcome.status === 'wait_dependencies') {
    deps.onPhase?.('chain')
    return runDependencyChainLoop(deps, input.app, input.title, rows)
  }
  if (outcome.status === 'installed') {
    return { kind: 'installed' }
  }
  if (outcome.status === 'failed') {
    return {
      kind: 'install-failed',
      reason: installOutcomeReason(outcome.row, '安装未完成，请到插件管理查看'),
      row: outcome.row
    }
  }
  return {
    kind: 'unconfirmed',
    reason: '安装请求未及时返回，服务端可能仍在后台执行；请到插件管理查看进度',
    row: outcome.row
  }
}

/**
 * 「直接安装/直接升级/继续安装」确认后的完整编排：
 * 安装请求 → 结果未知时按服务端快照轮询 → 依赖等待则跑依赖链 → 收敛。
 * 安装请求的传输类错误（超时、断连、取消）不代表服务端停止安装，交
 * 轮询裁决；业务拒绝原样抛出给调用方（http 层已提示）。
 */
export const runInstallOrchestration = async (
  deps: DependencyOrchestratorDeps,
  input: InstallOrchestrationInput
): Promise<InstallOrchestrationOutcome> => {
  if (deps.install === undefined) {
    throw new Error('install orchestration requires an install dependency')
  }
  deps.onPhase?.('install')
  let rows: DependencyChainRow[] = []
  try {
    const immediate = (await deps.install(input.app, input.confirmation)) ?? null
    if (immediate !== null) {
      rows = [immediate]
      if (immediate.state === 1 && dependencyKindOf(immediate) === null) {
        return { kind: 'installed' }
      }
    }
  } catch (error: unknown) {
    if (!isUncertainInstallError(error)) throw error
    // 超时/断连/取消：服务端通常仍在登记，交给快照轮询裁决。
  }
  return settleInstallBySnapshot(deps, input, rows)
}

/**
 * 断点续跑：整页 reload（vite watcher 对插件载荷目录整页刷新会销毁一切
 * JS 驻留编排）后，按 sessionStorage 记录的服务端行快照裁决当前阶段，
 * 从断点继续——服务端的依赖命令在客户端断连时已被终止回收，等待标志
 * 仍保留，直接重入队即可；安装仍在进行则轮询，已安装/失败/未登记则
 * 按终态收敛，绝不把陈旧编排复活为新安装（不重发 install 请求）。
 */
export const resumeInstallOrchestration = async (
  deps: DependencyOrchestratorDeps,
  input: InstallOrchestrationInput
): Promise<InstallOrchestrationOutcome> => {
  return settleInstallBySnapshot(deps, input, [])
}

// ========== 会话级断点续跑：sessionStorage 持久化 ==========
// 编排发起时写入最小进度，收敛后清除。sessionStorage 随整页 reload 保留
// （同 tab），关 tab 自然失效——不意外复活陈旧编排。

/** 编排阶段：install=安装请求已发出等待快照裁决；chain=依赖链执行中。 */
export type InstallOrchestrationPhase = 'install' | 'chain'

/** sessionStorage 中一条待续跑编排记录。 */
export interface InstallOrchestrationSession {
  app: string
  title: string
  phase: InstallOrchestrationPhase
  /** 发起时间（epoch ms），仅作诊断，不参与裁决。 */
  startedAt: number
}

/** 最小 Storage 形状（sessionStorage/localStorage 及测试替身）。 */
export interface OrchestrationSessionStorage {
  getItem(key: string): string | null
  setItem(key: string, value: string): void
  removeItem(key: string): void
}

export const ORCHESTRATION_SESSION_STORAGE_KEY = 'sandpackageInstallOrchestration.pending'

const normalizeSession = (value: unknown): InstallOrchestrationSession | null => {
  if (!value || typeof value !== 'object') return null
  const record = value as Partial<InstallOrchestrationSession>
  if (typeof record.app !== 'string' || record.app === '') return null
  return {
    app: record.app,
    title: typeof record.title === 'string' && record.title !== '' ? record.title : record.app,
    phase: record.phase === 'chain' ? 'chain' : 'install',
    startedAt: typeof record.startedAt === 'number' && isFinite(record.startedAt) ? record.startedAt : 0
  }
}

/** 读取全部待续跑编排记录；存储缺失、JSON 损坏或形状不符一律按空处理。 */
export const readOrchestrationSessions = (
  storage: OrchestrationSessionStorage
): InstallOrchestrationSession[] => {
  try {
    const raw = storage.getItem(ORCHESTRATION_SESSION_STORAGE_KEY)
    if (raw === null || raw === '') return []
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    return parsed
      .map(normalizeSession)
      .filter((item): item is InstallOrchestrationSession => item !== null)
  } catch {
    return []
  }
}

const writeOrchestrationSessions = (
  storage: OrchestrationSessionStorage,
  sessions: InstallOrchestrationSession[]
): void => {
  try {
    if (sessions.length === 0) {
      storage.removeItem(ORCHESTRATION_SESSION_STORAGE_KEY)
    } else {
      storage.setItem(ORCHESTRATION_SESSION_STORAGE_KEY, JSON.stringify(sessions))
    }
  } catch {
    // 存储不可用（隐私模式/配额）：退化为无断点续跑，不阻断编排。
  }
}

/** 按 app 写入/更新一条待续跑记录。 */
export const upsertOrchestrationSession = (
  storage: OrchestrationSessionStorage,
  session: InstallOrchestrationSession
): void => {
  const sessions = readOrchestrationSessions(storage).filter((item) => item.app !== session.app)
  sessions.push(session)
  writeOrchestrationSessions(storage, sessions)
}

/** 编排收敛（成功/失败/无法确认）后移除该插件的待续跑记录。 */
export const removeOrchestrationSession = (
  storage: OrchestrationSessionStorage,
  app: string
): void => {
  writeOrchestrationSessions(
    storage,
    readOrchestrationSessions(storage).filter((item) => item.app !== app)
  )
}

// ========== 幂等：在途依赖任务匹配（attach 而非重复入队） ==========

export interface DependencyTaskRef {
  command: string
  extend: string
}

/** 依赖任务的终端 extend 标识。 */
export const dependencyTaskExtend = (app: string): string => `module-install:${app}`

/**
 * 判断终端任务是否就是本插件该环节的依赖任务：续跑时发现已有
 * WAITING/CONNECTING/RUNNING 的匹配任务则 attach 其回调观察终态，
 * 不重复入队（同一页面会话内多次触发续跑/手工「处理依赖」并发时安全）。
 */
export const dependencyTaskMatches = (
  task: DependencyTaskRef,
  kind: DependencyKind,
  app: string
): boolean => {
  if (task.extend !== dependencyTaskExtend(app)) return false
  // composer.update 为固定命令；npm 侧经 addNodeTask 组装为 web-install.<manager>。
  return kind === 'composer' ? task.command === 'composer.update' : task.command.startsWith('web-install.')
}
