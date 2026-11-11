/**
 * 普通确认对话框的服务端确认串拼装与卸载依赖方文案。
 *
 * 这些字符串此前要求用户在对话框里逐字手打；现在由前端按行字段或检查
 * 端点响应自动生成并随请求提交，服务端仍按原样校验候选/登记身份，
 * 确认语义不变、但不再出现输入框。本文件保持纯函数，供 node:test 覆盖。
 */

/** 撤回尚未执行的升级候选（旧驱动）：与服务端 `DISCARD app@version` 校验一致。 */
export function discardUpgradeCandidateConfirmation(app: string, version: string): string {
  return `DISCARD ${app}@${version}`
}

/** 登记现有插件：与服务端 `REGISTER app@version` 校验一致。 */
export function registerExistingConfirmation(app: string, version: string): string {
  return `REGISTER ${app}@${version}`
}

export interface UninstallDependentLike {
  app: string
  title?: string | null
  version?: string | null
}

/** 依赖方展示名：优先通俗标题，缺失时退回插件标识。 */
export function uninstallDependentLabel(entry: UninstallDependentLike): string {
  const title = typeof entry.title === 'string' && entry.title.trim() !== '' ? entry.title : entry.app
  return title === entry.app ? entry.app : `${title}（${entry.app}）`
}

/**
 * 卸载对话框里的依赖方警告行；无依赖方返回空串，由调用方决定是否渲染。
 * 与服务端实际行为一致：存在依赖方时卸载会被拒绝，需先卸载依赖方。
 * 只出现插件通俗名与标识，不暴露内部名词。
 */
export function uninstallDependentsText(dependents: UninstallDependentLike[]): string {
  if (!Array.isArray(dependents) || dependents.length === 0) return ''
  const names = dependents.map(uninstallDependentLabel).join('、')
  return `以下已安装插件依赖它：${names}；需要先卸载这些插件，才能卸载本插件。`
}

/**
 * 卸载对话框里的「无法确认依赖」警告行：这些已安装插件的登记无法核对，
 * 服务端同样会拒绝卸载。无条目返回空串。
 */
export function uninstallUnverifiableText(entries: UninstallDependentLike[]): string {
  if (!Array.isArray(entries) || entries.length === 0) return ''
  const names = entries.map(uninstallDependentLabel).join('、')
  return `以下插件的安装信息暂时无法核对，无法确认是否依赖它：${names}；卸载可能被拒绝。`
}
