/**
 * 终端状态管理模块 - sandpackage插件
 *
 * 提供终端命令执行任务队列的状态管理
 *
 * @module store/terminal
 */
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@/store/modules/user'

/** 任务状态枚举 */
export enum TaskStatus {
  /** 等待执行 */
  WAITING = 1,
  /** 连接中 */
  CONNECTING = 2,
  /** 执行中 */
  RUNNING = 3,
  /** 执行成功 */
  SUCCESS = 4,
  /** 执行失败 */
  FAILED = 5,
  /** 未知 */
  UNKNOWN = 6
}

/** 终端任务接口 */
export interface TerminalTask {
  /** 任务唯一标识 */
  uuid: string
  /** 命令名称 */
  command: string
  /** 任务状态 */
  status: TaskStatus
  /** 执行消息 */
  message: string[]
  /** 创建时间 */
  createTime: string
  /** 是否显示消息 */
  showMessage: boolean
  /** 回调函数 */
  callback?: (status: number) => void
  /** 扩展参数 */
  extend: string
}

// 扩展 window 类型
declare global {
  interface Window {
    terminalAbortController?: AbortController
  }
}

/**
 * 生成UUID
 */
const generateUUID = (): string => {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0
    const v = c === 'x' ? r : (r & 0x3) | 0x8
    return v.toString(16)
  })
}

/**
 * 格式化日期时间
 */
const formatDateTime = (): string => {
  const now = new Date()
  return now.toLocaleString('zh-CN', {
    hour12: false,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  })
}

/**
 * 构建终端 SSE URL。令牌只通过请求头发送，不写入 URL。
 */
const buildTerminalUrl = (commandKey: string, extend: string): string => {
  const env = import.meta.env
  const baseURL = env.VITE_API_URL || ''
  const terminalUrl = '/app/sandpackage/index/terminal'
  return `${baseURL}${terminalUrl}?command=${encodeURIComponent(commandKey)}&extend=${encodeURIComponent(extend)}`
}

const terminalMessage = (value: unknown): string | null => {
  if (!value || typeof value !== 'object' || !('data' in value)) return null
  const data = value.data
  return typeof data === 'string' ? data : null
}

export const useTerminalStore = defineStore(
  'sandpackageTerminal',
  () => {
    // 状态
    const show = ref(false)
    const taskList = ref<TerminalTask[]>([])
    const npmRegistry = ref('npm')
    const packageManager = ref('pnpm')
    const composerRegistry = ref('composer')

    /**
     * 设置任务状态
     */
    const setTaskStatus = (idx: number, status: TaskStatus) => {
      if (taskList.value[idx]) {
        taskList.value[idx].status = status
        taskList.value[idx].showMessage = true
      }
    }

    /**
     * 添加任务消息
     */
    const addTaskMessage = (idx: number, message: string) => {
      if (taskList.value[idx]) {
        taskList.value[idx].message = taskList.value[idx].message.concat(message)
      }
    }

    /**
     * 切换任务消息显示
     */
    const setTaskShowMessage = (idx: number, val?: boolean) => {
      if (taskList.value[idx]) {
        taskList.value[idx].showMessage = val ?? !taskList.value[idx].showMessage
      }
    }

    /**
     * 清空任务列表
     */
    const cleanTaskList = () => {
      taskList.value = []
    }

    /**
     * 任务完成回调
     */
    const taskCompleted = (idx: number) => {
      const task = taskList.value[idx]
      if (!task || typeof task.callback !== 'function') return

      const status = task.status
      if (status === TaskStatus.FAILED || status === TaskStatus.UNKNOWN) {
        task.callback(TaskStatus.FAILED)
      } else if (status === TaskStatus.SUCCESS) {
        task.callback(TaskStatus.SUCCESS)
      }
    }

    /**
     * 根据猜测查找任务索引
     */
    const findTaskIdxFromGuess = (idx: number): number | false => {
      if (!taskList.value[idx]) {
        let taskKey = -1
        for (let i = 0; i < taskList.value.length; i++) {
          if (
            taskList.value[i].status === TaskStatus.CONNECTING ||
            taskList.value[i].status === TaskStatus.RUNNING
          ) {
            taskKey = i
          }
        }
        return taskKey === -1 ? false : taskKey
      }
      return idx
    }

    /**
     * 启动受认证的 SSE 请求。EventSource 无法附带 Authorization 头，
     * 因此用 fetch 流读取服务端事件。
     */
    const startEventSource = async (taskKey: number) => {
      const task = taskList.value[taskKey]
      if (!task) return

      window.terminalAbortController?.abort()
      const controller = new AbortController()
      window.terminalAbortController = controller
      const userStore = useUserStore()
      try {
        const response = await fetch(buildTerminalUrl(task.command, task.extend), {
          headers: {
            Accept: 'text/event-stream',
            Authorization: `Bearer ${userStore.accessToken}`
          },
          signal: controller.signal
        })
        if (!response.ok || !response.body) throw new Error('terminal connection failed')
        const reader = response.body.getReader()
        const decoder = new TextDecoder()
        let buffer = ''
        let completed = false
        while (!completed) {
          const result = await reader.read()
          if (result.done) break
          buffer += decoder.decode(result.value, { stream: true })
          const events = buffer.split(/\r?\n\r?\n/)
          buffer = events.pop() ?? ''
          for (const event of events) {
            const dataLine = event.split(/\r?\n/).find((line) => line.startsWith('data:'))
            if (!dataLine) continue
            let value: unknown
            try {
              value = JSON.parse(dataLine.slice(5).trim())
            } catch {
              continue
            }
            const data = terminalMessage(value)
            if (!data) continue
            const taskIdx = findTaskIdxFromGuess(taskKey)
            if (taskIdx === false) continue
            if (data === 'exec-error') {
              setTaskStatus(taskIdx, TaskStatus.FAILED)
              taskCompleted(taskIdx)
              startTask()
              completed = true
            } else if (data === 'exec-completed') {
              if (taskList.value[taskIdx].status !== TaskStatus.SUCCESS) {
                setTaskStatus(taskIdx, TaskStatus.FAILED)
              }
              taskCompleted(taskIdx)
              startTask()
              completed = true
            } else if (data === 'connection-success') {
              setTaskStatus(taskIdx, TaskStatus.RUNNING)
            } else if (data === 'exec-success') {
              setTaskStatus(taskIdx, TaskStatus.SUCCESS)
            } else {
              addTaskMessage(taskIdx, data)
            }
          }
        }
        if (!completed) throw new Error('terminal stream ended before completion')
      } catch {
        const taskIdx = findTaskIdxFromGuess(taskKey)
        if (taskIdx === false) return
        setTaskStatus(taskIdx, TaskStatus.FAILED)
        taskCompleted(taskIdx)
      } finally {
        if (window.terminalAbortController === controller) {
          window.terminalAbortController = undefined
        }
      }
    }

    /**
     * 添加 Node 相关任务
     */
    const addNodeTask = (
      command: string,
      extend: string = '',
      callback?: (status: TaskStatus) => void
    ) => {
      const manager = packageManager.value === 'unknown' ? 'npm' : packageManager.value
      const fullCommand = `${command}.${manager}`
      addTask(fullCommand, extend, callback)
    }

    /**
     * 添加任务
     */
    const addTask = (
      command: string,
      extend: string = '',
      callback?: (status: TaskStatus) => void
    ) => {
      const task: TerminalTask = {
        uuid: generateUUID(),
        createTime: formatDateTime(),
        status: TaskStatus.WAITING,
        command,
        message: [],
        showMessage: false,
        extend,
        callback
      }
      taskList.value.push(task)

      // 检查是否有已经失败的任务
      if (show.value === false) {
        for (const t of taskList.value) {
          if (t.status === TaskStatus.FAILED || t.status === TaskStatus.UNKNOWN) {
            ElMessage.warning('任务列表中存在失败的任务')
            break
          }
        }
      }

      startTask()
    }

    /**
     * 开始执行任务
     */
    const startTask = () => {
      let taskKey: number | null = null

      // 寻找可以开始执行的命令
      for (let i = 0; i < taskList.value.length; i++) {
        const task = taskList.value[i]
        if (task.status === TaskStatus.WAITING) {
          taskKey = i
          break
        }
        if (task.status === TaskStatus.CONNECTING || task.status === TaskStatus.RUNNING) {
          break
        }
      }

      if (taskKey !== null) {
        setTaskStatus(taskKey, TaskStatus.CONNECTING)
        startEventSource(taskKey)
      }
    }

    /**
     * 重试任务
     */
    const retryTask = (idx: number) => {
      if (taskList.value[idx]) {
        taskList.value[idx].message = []
        setTaskStatus(idx, TaskStatus.WAITING)
        startTask()
      }
    }

    /**
     * 删除任务
     */
    const delTask = (idx: number) => {
      const task = taskList.value[idx]
      if (task && task.status !== TaskStatus.CONNECTING && task.status !== TaskStatus.RUNNING) {
        taskList.value.splice(idx, 1)
      }
    }

    return {
      show,
      taskList,
      npmRegistry,
      packageManager,
      composerRegistry,
      setTaskStatus,
      addTaskMessage,
      setTaskShowMessage,
      cleanTaskList,
      addNodeTask,
      addTask,
      retryTask,
      delTask,
      startTask
    }
  },
  {
    persist: {
      key: 'sandpackageTerminal',
      storage: localStorage,
      pick: ['npmRegistry', 'composerRegistry', 'packageManager']
    }
  }
)

export default useTerminalStore
