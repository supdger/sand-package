export interface ReleaseCheck {
  code: string
  state: string
  message: string
}

export function readReleaseFailure(checks: ReleaseCheck[]): string {
  return checks
    .filter((check) => check.code === 'repository' && check.state === 'fail')
    .map((check) => check.message)
    .join('；')
}

/** 请求没回来，或官方发行这一项失败，都不能当成已经是最新版。 */
export function isReleaseReadFailed(checks: ReleaseCheck[] | null, requestError: string): boolean {
  if (requestError.trim() !== '') return true
  return readReleaseFailure(checks ?? []) !== ''
}

/** 复制用的技术原因。页面上不展示这段，只保留状态码或超时。 */
export function describeRequestFailure(message: string, statusCode: number, timedOut: boolean): string {
  if (timedOut) return '请求超时'
  if (statusCode >= 400) {
    const text = message.trim() !== '' ? message.trim() : '请求未完成'
    return text.includes(String(statusCode)) ? text : `${text}（HTTP ${statusCode}）`
  }
  return message.trim() !== '' ? message.trim() : '请求未完成，请重新连接查询实际结果。'
}
