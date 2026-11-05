/**
 * 一键安装依赖链编排器 - sandpackage插件
 *
 * 应用级宿主（pinia store），不依附安装视图组件生命周期：安装请求、
 * 服务端状态轮询与依赖任务入队全部在这里持有，页面被 keep-alive 停用、
 * 重激活或动态路由重建都不影响链推进。安装视图只渲染这里暴露的状态
 * （busy/failure/rows），并在依赖命令需要执行时按 panelTick 展示命令行说明。
 *
 * 读通道（fetchRows）刻意不传 AbortSignal、不检查视图 readsActive——
 * 0.2.18 的缺陷正是停用窗口内 getList 静默空转导致轮询失能。安装
 * 请求同样由本 store 发起，视图停用无法中止它。
 *
 * 会话级断点续跑：安装器把插件前端载荷写入 src/views/plugin/** 时 vite
 * dev watcher 会触发整页 reload，JS 上下文整体销毁、本 store 随之消失。
 * 编排发起时把最小进度（app/title/阶段/发起时间）写入 sessionStorage
 * ——同 tab 整页刷新保留、关 tab 自然失效；视图挂载/重激活时调用
 * resumeIfPending() 按服务端行快照（state 与 *_dependent_wait_install）
 * 裁决断点并续跑：安装仍进行则轮询、依赖等待则重入队（服务端在客户端
 * 断连时已终止并回收依赖进程，等待标志保留）、已安装/失败/未登记则按
 * 终态收敛并清理记录。
 *
 * @module store/install-orchestrator
 */
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import sandpackageApi from '../api/index'
import {
  dependencyPlanItemsOf,
  readOrchestrationSessions,
  removeOrchestrationSession,
  resumeInstallOrchestration,
  runDependencyChainLoop,
  runInstallOrchestration,
  upsertOrchestrationSession,
  type DependencyChainRow,
  type DependencyKind,
  type DependencyOrchestratorDeps,
  type DependencyPlanItem,
  type DependencyQueueEntry,
  type InstallChainFailure,
  type InstallOrchestrationInput,
  type InstallOrchestrationOutcome,
  type InstallOrchestrationPhase,
  type OrchestrationSessionStorage
} from '../install/dependency-chain'

export const useInstallOrchestratorStore = defineStore('sandpackageInstallOrchestrator', () => {
  /** 最近一次服务端登记快照；编排器自有读通道的落点，视图可做交叉校验。 */
  const rows = ref<DependencyChainRow[]>([])
  /** 正在编排的插件标识；空串表示空闲。 */
  const runningApp = ref('')
  /** 最近一次依赖链失败；终端面板据此展示通俗原因与一键重试。 */
  const failure = ref<InstallChainFailure | null>(null)
  /**
   * 失败面板弹出请求计数：依赖链失败时自增。视图监听它打开终端面板；
   * 视图处于 keep-alive 停用期间 watcher 暂停，重激活后补开——配合
   * onActivated 中对 failure 的检查，保证失败面板不丢。
   */
  const panelTick = ref(0)

  const busy = computed(() => runningApp.value !== '')

  /**
   * sessionStorage 访问器：非浏览器环境或隐私模式抛错时返回 null，
   * 断点续跑整体退化为无持久化，不阻断编排本体。
   */
  const sessionStorageOf = (): OrchestrationSessionStorage | null => {
    try {
      return typeof window === 'undefined' ? null : window.sessionStorage
    } catch {
      return null
    }
  }

  const persistSession = (
    app: string,
    title: string,
    phase: InstallOrchestrationPhase,
    queue?: DependencyQueueEntry[],
    depIndex?: number
  ): void => {
    const storage = sessionStorageOf()
    if (storage === null) return
    upsertOrchestrationSession(storage, { app, title, phase, startedAt: Date.now(), queue, depIndex })
  }

  const dropSession = (app: string): void => {
    const storage = sessionStorageOf()
    if (storage === null) return
    removeOrchestrationSession(storage, app)
  }

  /** resumeIfPending 的重入防护：同一页面会话内多个触发点并发调用安全。 */
  let resumeRunning = false

  /**
   * 编排器自有读通道：直接请求服务端登记快照，不携带 AbortSignal，
   * 不经视图 localRead/cancelRepositoryReads，也不检查 readsActive。
   * 读失败保留上次快照——轮询预算与人工兜底不变，不中断链。
   */
  const fetchRows = async (): Promise<DependencyChainRow[]> => {
    try {
      const resp = await sandpackageApi.getAppList({ showErrorMessage: false })
      rows.value = resp?.data ?? []
    } catch {
      // 保留上次快照；连续不可读时由轮询预算收敛为 unconfirmed。
    }
    return rows.value
  }

  /**
   * 浏览器不执行依赖命令。登记仍停在等待依赖时，链在这里停下并给出
   * CLI；命令本身由 `php webman sandpackage:dependency` 在独立进程中运行。
   */
  const runDependencyTask = (_kind: DependencyKind, _app: string): Promise<boolean> =>
    Promise.resolve(false)

  const failureReason = (app: string, kind: DependencyKind): string => {
    const task = kind === 'composer' ? 'composer' : 'npm'
    return `浏览器不能执行依赖命令。请在宿主 server 目录运行 php webman sandpackage:dependency run ${app} ${task}。中断现场用 php webman sandpackage:recover inspect-dependency ${app}`
  }

  const lockConflict = (_app: string, _kind: DependencyKind): boolean => false

  /**
   * 依赖消解：只读计划端点 → 编排队列。计划端点本身失败时抛出，由
   * 编排核心降级为单插件编排（服务端依赖断言仍是最终防线）。
   */
  const resolveDeps = async (app: string): Promise<DependencyPlanItem[]> => {
    const plan = await sandpackageApi.inspectDependencies({ appName: app })
    return dependencyPlanItemsOf(plan)
  }

  /**
   * 准备依赖候选：market 项经仓库下载暂存（服务端按 state/sha 幂等），
   * market 为空表示服务端已登记满足版本的候选，直接复用当前快照行。
   */
  const prepareDep = async (item: DependencyPlanItem): Promise<DependencyChainRow> => {
    if (item.market === null) {
      const snapshot = (await fetchRows()).find((row) => row.app === item.app)
      if (!snapshot) {
        throw new Error(`无法读取 ${item.title} 的安装状态，请刷新后重试`)
      }
      return snapshot
    }
    return await sandpackageApi.downloadRepositoryPlugin({
      app: item.app,
      version: item.market.version,
      sha256: item.market.sha256
    })
  }

  const chainDeps = (
    install?: DependencyOrchestratorDeps['install'],
    onPhase?: DependencyOrchestratorDeps['onPhase'],
    onQueue?: DependencyOrchestratorDeps['onQueue']
  ): DependencyOrchestratorDeps => ({
    install,
    fetchRows,
    runDependencyTask,
    failureReason,
    lockConflict,
    resolveDeps,
    prepareDep,
    onQueue,
    onPhase
  })

  /**
   * 计划阶段失败的一键重试入口：依赖消解失败（仓库不可用/依赖被拒/
   * dep 安装中断）时，重试应重跑完整编排而非只续依赖链。start 与行级
   * 续做各自登记自己的重入口——续做重试只消解依赖并按快照续链，绝不
   * 重发目标安装请求（目标可能还停在 state=4）。
   */
  const planRetryHandler = ref<(() => Promise<InstallOrchestrationOutcome | null>) | null>(null)

  /** 收敛收尾：依赖失败登记 failure 并请求弹出面板；其余 outcome 原样返回。 */
  const settle = (outcome: InstallOrchestrationOutcome): InstallOrchestrationOutcome => {
    if (outcome.kind === 'chain-failed') {
      failure.value = outcome.failure
      panelTick.value += 1
    }
    return outcome
  }

  /**
   * 「直接安装/直接升级/继续安装」确认后的编排入口。安装请求由本 action
   * 持有，视图停用无法中止；结果未知时按服务端快照轮询，依赖等待则
   * 自动入队依赖链。业务拒绝原样抛给调用方（http 层已提示）。
   */
  const start = async (input: InstallOrchestrationInput): Promise<InstallOrchestrationOutcome> => {
    if (runningApp.value !== '') {
      return {
        kind: 'unconfirmed',
        reason: '已有插件安装或依赖任务进行中，请稍后重试',
        row: null
      }
    }
    failure.value = null
    planRetryHandler.value = () => start(input)
    runningApp.value = input.app
    // 断点记录先于安装请求写入：整页 reload 发生在安装任何阶段都能续跑。
    persistSession(input.app, input.title, 'install')
    try {
      const outcome = await runInstallOrchestration(
        chainDeps(
          (app, confirmation, restart) =>
            sandpackageApi.installApp({ appName: app, confirmation, restart }),
          (phase) => persistSession(input.app, input.title, phase),
          (queue, index) => persistSession(input.app, input.title, 'install', queue, index)
        ),
        input
      )
      return settle(outcome)
    } finally {
      runningApp.value = ''
      // 终态（成功/失败/无法确认/业务拒绝）即清除断点；面板失败态独立保留。
      dropSession(input.app)
      void resumeIfPending()
    }
  }

  /**
   * 终端面板「一键重试」：依赖任务失败仅续跑依赖链；依赖消解（计划
   * 阶段）失败重跑整个编排——计划端点幂等只读，已完成的依赖自然跳过。
   */
  const retryChain = async (): Promise<InstallOrchestrationOutcome | null> => {
    const current = failure.value
    if (!current || runningApp.value !== '') return null
    if (current.plan === true && planRetryHandler.value !== null) {
      return planRetryHandler.value()
    }
    runningApp.value = current.app
    persistSession(current.app, current.title, 'chain')
    try {
      const outcome = await runDependencyChainLoop(
        chainDeps(),
        current.app,
        current.title,
        rows.value
      )
      const settled = settle(outcome)
      if (settled.kind === 'installed') failure.value = null
      return settled
    } finally {
      runningApp.value = ''
      dropSession(current.app)
      void resumeIfPending()
    }
  }

  /**
   * 行级「一键续做」：无需 session 记录，对等待依赖安装的行
   * （composer_dependent_wait_install / npm_dependent_wait_install=1）
   * 走完整续跑编排——先按计划端点重算并补齐未满足的插件依赖（例如升级
   * 中断在依赖等待、已装依赖仍低于目标要求的场景），再按服务端行快照
   * 裁决续跑剩余依赖任务；绝不重发目标安装请求。install 注入仅供依赖
   * 消解使用（dep 需要市场升级/新装时经它发起，restart=false）。
   * 复用同一 runningApp 单飞与在途任务 attach，重复点击与并发触发安全。
   */
  const resumeChainForRow = async (
    app: string,
    title: string
  ): Promise<InstallOrchestrationOutcome | null> => {
    if (runningApp.value !== '') return null
    runningApp.value = app
    planRetryHandler.value = () => resumeChainForRow(app, title)
    persistSession(app, title, 'install')
    try {
      const outcome = await resumeInstallOrchestration(
        chainDeps(
          (depApp, confirmation, restart) =>
            sandpackageApi.installApp({ appName: depApp, confirmation, restart }),
          (phase) => persistSession(app, title, phase),
          (queue, index) => persistSession(app, title, 'install', queue, index)
        ),
        { app, title }
      )
      const settled = settle(outcome)
      if (settled.kind === 'installed') failure.value = null
      return settled
    } finally {
      runningApp.value = ''
      dropSession(app)
      void resumeIfPending()
    }
  }

  /**
   * 整页 reload 后的断点续跑入口（视图 onMounted/onActivated 调用）。
   * 读取 sessionStorage 待续跑记录，逐个按服务端行快照裁决续跑；
   * 无记录或本 store 不可达 sessionStorage 时为空操作。终态一律清理
   * 记录——sessionStorage 有但服务端从未登记（行缺失）也按快照终态
   * 收敛，不复活陈旧编排。
   */
  const resumeIfPending = async (): Promise<InstallOrchestrationOutcome[]> => {
    const storage = sessionStorageOf()
    if (storage === null || resumeRunning) return []
    const pending = readOrchestrationSessions(storage)
    if (pending.length === 0) return []
    resumeRunning = true
    const outcomes: InstallOrchestrationOutcome[] = []
    try {
      for (const session of pending) {
        if (runningApp.value !== '') break
        runningApp.value = session.app
        // 整页 reload 也可能落在依赖消解中途（已装依赖仍不满足）：install
        // 注入仅供 dep 消解发起市场升级/新装；目标自身绝不重发安装请求。
        planRetryHandler.value = () => resumeChainForRow(session.app, session.title)
        try {
          const outcome = await resumeInstallOrchestration(
            chainDeps(
              (depApp, confirmation, restart) =>
                sandpackageApi.installApp({ appName: depApp, confirmation, restart }),
              (phase) => persistSession(session.app, session.title, phase),
              (queue, index) => persistSession(session.app, session.title, 'install', queue, index)
            ),
            { app: session.app, title: session.title }
          )
          const settled = settle(outcome)
          if (settled.kind === 'installed') failure.value = null
          outcomes.push(settled)
        } catch {
          // 续跑异常不阻断其余待续跑记录；断点由 finally 清理，避免反复重放。
        } finally {
          runningApp.value = ''
          dropSession(session.app)
        }
      }
    } finally {
      resumeRunning = false
    }
    return outcomes
  }

  return {
    rows,
    runningApp,
    busy,
    failure,
    panelTick,
    fetchRows,
    start,
    retryChain,
    resumeChainForRow,
    resumeIfPending
  }
})

export default useInstallOrchestratorStore
