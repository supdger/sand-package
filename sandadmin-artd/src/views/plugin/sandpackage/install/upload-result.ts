/**
 * 上传结果文案口径
 *
 * 后端 InstallLogic 对「压缩包本身无效」类失败统一下发
 * INVALID_PACKAGE_CODE 业务码：页面只展示固定白话，原始细节只进复制内容。
 * 其余失败（版本冲突、宿主不兼容等）原文展示后端消息，供判断下一步。
 *
 * @module install/upload-result
 */

/** 与后端 InstallLogic::INVALID_PACKAGE_CODE 一致。 */
export const INVALID_PACKAGE_CODE = 422

/** 包体无效时页面只展示这一句白话。 */
export const INVALID_PACKAGE_MESSAGE = '这个压缩包不是有效的插件包，请向提供插件的人要一个正确的包。'

export interface UploadFailureText {
  /** 页面上展示的标题 */
  title: string
  /** 「复制问题信息」复制的原始细节 */
  detail: string
}

/** HttpError 的业务错误没有 transport 标记，HTTP/网络层错误才有。 */
const readBusinessCode = (error: unknown): number | undefined => {
  if (typeof error !== 'object' || error === null) return undefined
  const record = error as { code?: unknown; transport?: unknown }
  if (record.transport !== undefined || typeof record.code !== 'number') return undefined
  return record.code
}

export function describeUploadFailure(error: unknown): UploadFailureText {
  const detail =
    error instanceof Error && error.message !== '' ? error.message : '上传没有完成，请再试一次'
  const title = readBusinessCode(error) === INVALID_PACKAGE_CODE ? INVALID_PACKAGE_MESSAGE : detail
  return { title, detail }
}
