/**
 * 一键安装依赖链的纯判定逻辑。
 *
 * 依赖任务只允许经认证管理员会话的 SSE 终端启动（TerminalRunner::isSuperAdmin），
 * 后端没有不经会话的执行器，因此自动依赖链只能由页面触发。这里的判定以服务端
 * 登记状态（info.ini 的 state 与 *_dependent_wait_install 标志）为唯一依据，
 * 不依赖安装请求的客户端成败：axios 超时或连接断开时，服务端 PHP 仍在后台
 * 继续完成安装登记，按服务端状态轮询收敛才不会让链静默缺失。
 *
 * 本模块不引用 Vue / axios / 宿主别名，供 node:test 直接覆盖。
 */

export interface DependencyChainRow {
  app: string
  state?: number
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
 * - 依赖等待标志 → 可立即入队依赖任务
 * - state=1 → 安装完成
 * - state 3/5/6/7/8 或未知异常态 → 安装失败或需人工处理
 * - 其余（安装进行中的 state=2/4、行暂缺）→ 仍需等待
 */
export const installOutcomeOf = (
  row: DependencyChainRow | null | undefined
): InstallOutcomeStatus => {
  if (!row) return 'pending'
  if (dependencyKindOf(row) !== null) return 'wait_dependencies'
  const state = row.state
  if (state === 1) return 'installed'
  if (state === 2 || state === 4) return 'pending'
  if (typeof state === 'number' && (state === 0 || state === 3 || state >= 5)) return 'failed'
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
