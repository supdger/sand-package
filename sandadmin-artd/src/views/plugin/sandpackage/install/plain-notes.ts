const codedToken =
  /`[^`]*`|\b[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9_]+\b|\b[a-z]+[A-Z][A-Za-z0-9]*\b|\b[A-Z][A-Z0-9_]{2,}\b|\.[A-Za-z][A-Za-z0-9_-]*\b|\b[A-Za-z0-9_-]+\.(?:env|php|ts|vue|js|json|sql|md)\b|\([^)\n]{0,80}\)|\/[A-Za-z0-9_./-]+/

// 发版说明里给技术人员看的核验套话；版本弹窗只留白话，这些句子整句不进页面。
const verificationWord =
  /构建|逐字节|条目|位一致|双阶段|载荷|签名|sha256|哈希|SQL|sql|迁移|回归|验收|截图|工单|宿主|install|update|uninstall|manifest/i

const emptyNotes = '该版本尚未提供更新摘要，可查看包内更新日志。'

/** 逐句拆开，去掉含标识、代码、路径或不含中文的句子。 */
const plainSentences = (notes: string): string[] => {
  const withoutFence = notes.replace(/```[\s\S]*?```/g, '\n')
  return withoutFence
    .split(/(?<=[。！？!?；;\n])/)
    .map((sentence) => sentence.replace(/`[^`]*`/g, '').trim())
    .filter(
      (sentence) =>
        sentence !== '' && !codedToken.test(sentence) && /[一-鿿]/.test(sentence)
    )
}

/** 版本说明只留白话。含标识、代码或路径的句子整句去掉。 */
export function stripCodedNotes(notes: string, emptyText = emptyNotes): string {
  const plain = plainSentences(notes).join('').replace(/\s+/g, ' ').trim()
  return plain === '' ? emptyText : plain
}

/** 版本弹窗的更新说明：先按白话句筛，再去掉核验套话；没有白话可写时给占位句。 */
export function describeReleaseNotes(notes: string): string {
  const plain = plainSentences(notes)
    .filter((sentence) => !verificationWord.test(sentence))
    .join('')
    .replace(/\s+/g, ' ')
    .trim()
  return plain === '' ? '这个版本没写更新说明' : plain
}
