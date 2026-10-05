/** Recovery is opt-in for the two idempotent repository reads, never writes. */
const RETRY_DELAYS_MS = [1000, 2000, 3000, 4000]
const RETRY_START_BUDGET_MS = 15000

function waitForRetry(delay: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const abort = (): void => {
      clearTimeout(timer)
      reject(new Error('Repository read cancelled'))
    }
    const timer = setTimeout(() => {
      signal.removeEventListener('abort', abort)
      resolve()
    }, delay)
    signal.addEventListener('abort', abort, { once: true })
    if (signal.aborted) abort()
  })
}

/**
 * A new read invalidates and aborts the previous one. Callers must guard all
 * state changes with isCurrent(), including their catch/finally branches.
 * The budget limits retry starts; it does not shorten the request's timeout.
 */
export function createRecoverableRead(isRecoverable: (error: unknown) => boolean) {
  let current: AbortController | undefined

  const cancel = (): void => {
    current?.abort()
    current = undefined
  }

  const begin = () => {
    cancel()
    const controller = new AbortController()
    current = controller
    const isCurrent = (): boolean => current === controller && !controller.signal.aborted
    const run = async <T>(read: (signal: AbortSignal) => Promise<T>): Promise<T> => {
      const startedAt = Date.now()
      let retries = 0
      while (isCurrent()) {
        try {
          return await read(controller.signal)
        } catch (error: unknown) {
          const delay = RETRY_DELAYS_MS[retries]
          if (
            !isCurrent() ||
            !isRecoverable(error) ||
            delay === undefined ||
            Date.now() - startedAt + delay >= RETRY_START_BUDGET_MS
          ) throw error
          retries += 1
          await waitForRetry(delay, controller.signal)
          if (Date.now() - startedAt >= RETRY_START_BUDGET_MS) throw error
        }
      }
      throw new Error('Repository read cancelled')
    }
    return { run, isCurrent }
  }

  return { begin, cancel }
}
