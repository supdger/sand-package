/** 框架兼容列用白话，不直接显示 >= 这种写法。 */
export function formatSupportText(support: string): string {
  const parts = support
    .split('|')
    .map((part) => part.trim())
    .filter((part) => part !== '')
  if (parts.length === 0) return '未声明'
  return parts.map(formatSupportPart).join('，或')
}

function formatSupportPart(part: string): string {
  const range = /^>=\s*([0-9A-Za-z.+-]+)(?:\s+<=\s*([0-9A-Za-z.+-]+))?$/.exec(part)
  if (!range) return `需要 ${part}`
  if (range[2]) return `需要 ${range[1]} 至 ${range[2]}`
  return `需要 ${range[1]} 及以上`
}
