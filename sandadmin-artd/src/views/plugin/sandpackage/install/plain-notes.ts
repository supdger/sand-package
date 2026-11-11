const codedToken =
  /`[^`]*`|\b[A-Za-z][A-Za-z0-9]*_[A-Za-z0-9_]+\b|\b[a-z]+[A-Z][A-Za-z0-9]*\b|\b[A-Z][A-Z0-9_]{2,}\b|\.[A-Za-z][A-Za-z0-9_-]*\b|\b[A-Za-z0-9_-]+\.(?:env|php|ts|vue|js|json|sql|md)\b|\([^)\n]{0,80}\)|\/[A-Za-z0-9_./-]+/

const emptyNotes = '该版本尚未提供更新摘要，可查看包内更新日志。'

/** 版本说明只留白话。含标识、代码或路径的句子整句去掉。 */
export function stripCodedNotes(notes: string): string {
  const withoutFence = notes.replace(/```[\s\S]*?```/g, '\n')
  const sentences = withoutFence
    .split(/(?<=[。！？!?；;\n])/)
    .map((sentence) => sentence.replace(/`[^`]*`/g, '').trim())
    .filter((sentence) => sentence !== '')
  const kept = sentences.filter(
    (sentence) => !codedToken.test(sentence) && /[\u4e00-\u9fff]/.test(sentence)
  )
  const plain = kept.join('').replace(/\s+/g, ' ').trim()
  return plain === '' ? emptyNotes : plain
}
