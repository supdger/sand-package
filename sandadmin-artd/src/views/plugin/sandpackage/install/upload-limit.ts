/** 页面上只写兆字节，不写原始字节数。 */
export function formatUploadLimit(bytes: number): string {
  const rounded = Math.round((bytes / (1024 * 1024)) * 10) / 10
  const text = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1)
  return `最大 ${text} MB`
}

export function describeOversize(maxBytes: number, layer = '前端校验'): string {
  return `插件包超过${formatUploadLimit(maxBytes)}（${layer}）`
}
