/** 页面上只写兆字节，不写原始字节数。 */
export function formatUploadLimit(bytes: number): string {
  const rounded = Math.round((bytes / (1024 * 1024)) * 10) / 10
  const text = Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1)
  return `最大 ${text} MB`
}

/** 实际大小保留一位小数；上限是整数兆字节时不补 .0。 */
export function formatFileMegabytes(bytes: number): string {
  return (Math.round((bytes / (1024 * 1024)) * 10) / 10).toFixed(1)
}

export function describeOversize(maxBytes: number, fileBytes: number): string {
  return `插件包不能超过 ${formatUploadLimit(maxBytes).replace(/^最大 /, '')}，这个有 ${formatFileMegabytes(fileBytes)} MB`
}
