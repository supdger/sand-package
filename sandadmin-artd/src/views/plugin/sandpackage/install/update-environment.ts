/** 与 release-lookup.ts 的 ReleaseCheck 同形；独立声明让本模块不引用宿主别名，供 node:test 直接覆盖。 */
export interface EnvironmentCheck {
  code: string
  state: string
  message: string
}

/**
 * 环境不支持时黄条展示的白话：没有新版本时说明不用做什么，
 * 有新版本时带接口返回的真实版本号；「符号链接」这类细节只进复制内容。
 */
export function describeEnvironmentUnsupported(latestVersions: string[]): string {
  if (latestVersions.length === 0) {
    return '这台服务器不支持在网页里升级。现在已经是最新版，不用做什么；以后有新版本时，请让技术人员按升级说明手动升级。'
  }
  return `有新版本 ${latestVersions.join('、')}。这台服务器不支持在网页里升级，请让技术人员按升级说明手动升级。`
}

/** 环境类检查失败的技术原因合集；官方发行读取失败属于可重试路径，不算环境不支持。 */
export function readEnvironmentFailure(checks: EnvironmentCheck[]): string {
  return checks
    .filter((check) => check.code !== 'repository' && check.state === 'fail')
    .map((check) => check.message)
    .join('；')
}

export function isEnvironmentUnsupported(checks: EnvironmentCheck[] | null): boolean {
  return readEnvironmentFailure(checks ?? []) !== ''
}

/** 「复制问题信息」的完整技术细节：请求级失败优先，否则全部检查失败原因合并。 */
export function describeUpdateProblem(checks: EnvironmentCheck[] | null, requestError: string): string {
  if (requestError.trim() !== '') return requestError.trim()
  return (checks ?? [])
    .filter((check) => check.state === 'fail')
    .map((check) => check.message)
    .join('；')
}
