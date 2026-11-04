/**
 * 一键安装依赖链编排器 - sandpackage插件
 *
 * 应用级宿主（pinia store），不依附安装视图组件生命周期：安装请求、
 * 服务端状态轮询与依赖任务入队全部在这里持有，页面被 keep-alive 停用、
 * 重激活或动态路由重建都不影响链推进。安装视图只渲染这里暴露的状态
 * （busy/failure/rows），并在失败时按 panelTick 弹出终端面板。
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
import { TaskStatus, useTerminalStore } from './terminal'
import {
  DEPENDENCY_LOCK_CONFLICT_TOKEN,
  dependencyTaskMatches,
  readOrchestrationSessions,
  removeOrchestrationSession,
  resumeInstallOrchestration,
  runDependencyChainLoop,
  runInstallOrchestration,
  upsertOrchestrationSession,
  type DependencyChainRow,
  type DependencyKind,
  type DependencyOrchestratorDeps,
  type InstallChainFailure,
  type InstallOrchestrationInput,
  type InstallOrchestrationOutcome,
  type InstallOrchestrationPhase,
  type OrchestrationSessionStorage
} from '../install/dependency-chain'

export const useInstallOrchestratorStore = defineStore('sandpackageInstallOrchestrator', () => {
  const terminalStore = useTerminalStore()

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

  const persistSession = (app: string, title: string, phase: InstallOrchestrationPhase): void => {
    const storage = sessionStorageOf()
    if (storage === null) return
    upsertOrchestrationSession(storage, { app, title, phase, startedAt: Date.now() })
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
   * 依赖任务入队并等待终态。终端任务队列与 fetch-SSE 都在
   * sandpackageTerminal store 内运行，不依附任何组件。
   *
   * 幂等：同一插件同一环节已有在途任务（续跑并发触发或人工「处理依赖」
   * 已入队）时 attach 其回调观察终态，不重复入队。服务端的依赖进程在
   * 客户端断连时被终止回收，不存在需 attach 的服务端在途任务。
   */
  const runDependencyTask = (kind: DependencyKind, app: string): Promise<boolean> =>
    new Promise<boolean>((resolve) => {
      const extend = 'module-install:' + app
      const done = (status: TaskStatus): void => {
        resolve(status === TaskStatus.SUCCESS)
      }
      const inflight = terminalStore.taskList.find(
        (item) =>
          dependencyTaskMatches(item, kind, app) &&
          (item.status === TaskStatus.WAITING ||
            item.status === TaskStatus.CONNECTING ||
            item.status === TaskStatus.RUNNING)
      )
      if (inflight) {
        const previous = inflight.callback
        inflight.callback = (status: TaskStatus): void => {
          if (typeof previous === 'function') previous(status)
          done(status)
        }
        return
      }
      if (kind === 'composer') {
        terminalStore.addTask('composer.update', extend, done)
      } else {
        terminalStore.addNodeTask('web-install', extend, done)
      }
    })

  /** 最近一次匹配失败的依赖任务；通俗原因与锁冲突判定共用该查找。 */
  const dependencyFailureTask = (app: string, kind: DependencyKind) => {
    const extend = 'module-install:' + app
    const prefix = kind === 'composer' ? 'composer.' : 'web-install.'
    return [...terminalStore.taskList]
      .reverse()
      .find(
        (item) =>
          item.extend === extend &&
          item.command.startsWith(prefix) &&
          item.status === TaskStatus.FAILED
      )
  }

  /** 依赖任务失败后从终端任务输出里归纳通俗原因（与原视图内实现一致）。 */
  const failureReason = (app: string, kind: DependencyKind): string => {
    const task = dependencyFailureTask(app, kind)
    const messages = (task?.message ?? [])
      .map((line) => line.trim())
      .filter((line) => line !== '')
    const hinted = messages.filter(
      (line) =>
        line.includes('【原因】') ||
        line.includes('【详情】') ||
        line.includes(DEPENDENCY_LOCK_CONFLICT_TOKEN)
    )
    const last = hinted.length > 0 ? hinted[hinted.length - 1] : messages[messages.length - 1]
    return last && last !== '' ? last : '请查看终端输出中的具体错误'
  }

  /**
   * 依赖任务失败是否为「安装操作锁冲突」瞬态：服务端把 flock 冲突
   * （OperationLockException）映射为失败详情里的【锁冲突】记号，
   * 这里只按记号判定——行快照看不到的解锁残余窗口或其他插件的
   * 在途事务都由它兜底，编排器在预算内重试入队而非判链失败。
   */
  const lockConflict = (app: string, kind: DependencyKind): boolean => {
    const task = dependencyFailureTask(app, kind)
    return (task?.message ?? []).some((line) => line.includes(DEPENDENCY_LOCK_CONFLICT_TOKEN))
  }

  const chainDeps = (
    install?: DependencyOrchestratorDeps['install'],
    onPhase?: DependencyOrchestratorDeps['onPhase']
  ): DependencyOrchestratorDeps => ({
    install,
    fetchRows,
    runDependencyTask,
    failureReason,
    lockConflict,
    onPhase
  })

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
    runningApp.value = input.app
    // 断点记录先于安装请求写入：整页 reload 发生在安装任何阶段都能续跑。
    persistSession(input.app, input.title, 'install')
    try {
      const outcome = await runInstallOrchestration(
        chainDeps(
          (app, confirmation) => sandpackageApi.installApp({ appName: app, confirmation }),
          (phase) => persistSession(input.app, input.title, phase)
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

  /** 终端面板「一键重试」：仅续跑依赖链，不重发安装请求。 */
  const retryChain = async (): Promise<InstallOrchestrationOutcome | null> => {
    const current = failure.value
    if (!current || runningApp.value !== '') return null
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
        try {
          const outcome = await resumeInstallOrchestration(
            chainDeps(undefined, (phase) => persistSession(session.app, session.title, phase)),
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
    resumeIfPending
  }
})

export default useInstallOrchestratorStore
