function escapeHtml(text: string): string {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function renderInline(raw: string): string {
  let output = ''
  let rest = raw
  while (rest !== '') {
    const code = /^`([^`]+)`/.exec(rest)
    if (code) {
      output += '<code>' + escapeHtml(code[1] ?? '') + '</code>'
      rest = rest.slice(code[0].length)
      continue
    }
    const bold = /^\*\*([^*]+)\*\*/.exec(rest)
    if (bold) {
      output += '<strong>' + escapeHtml(bold[1] ?? '') + '</strong>'
      rest = rest.slice(bold[0].length)
      continue
    }
    output += escapeHtml(rest[0] ?? '')
    rest = rest.slice(1)
  }
  return output
}

function splitCells(line: string): string[] {
  const trimmed = line.trim().replace(/^\|/, '').replace(/\|$/, '')
  return trimmed.split('|').map((cell) => cell.trim())
}

function isRuleRow(line: string): boolean {
  return /^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/.test(line)
}

function isTableStart(lines: string[], index: number): boolean {
  const current = lines[index] ?? ''
  const next = lines[index + 1] ?? ''
  return current.includes('|') && isRuleRow(next)
}

function renderTable(lines: string[]): string {
  const header = splitCells(lines[0] ?? '')
  const body = lines.slice(2).filter((line) => line.trim() !== '')
  const head = '<tr>' + header.map((cell) => '<th>' + renderInline(cell) + '</th>').join('') + '</tr>'
  const rows = body
    .map((line) => {
      const cells = splitCells(line)
      return '<tr>' + cells.map((cell) => '<td>' + renderInline(cell) + '</td>').join('') + '</tr>'
    })
    .join('')
  return '<table><thead>' + head + '</thead><tbody>' + rows + '</tbody></table>'
}

function isBlockLine(line: string): boolean {
  return (
    line.startsWith('```') ||
    /^(#{1,6})\s+/.test(line) ||
    /^\s*[-*]\s+/.test(line) ||
    /^\s*\d+\.\s+/.test(line) ||
    (line.includes('|') && isRuleRow(line))
  )
}

/**
 * 只排标题、列表、表格和代码块。先转义再拼标签，页面上的 HTML 和脚本不会执行。
 */
export function renderPlainMarkdown(source: string): string {
  const lines = source.replace(/\r\n/g, '\n').split('\n')
  const html: string[] = []
  let index = 0
  while (index < lines.length) {
    const line = lines[index] ?? ''
    if (line.startsWith('```')) {
      const code: string[] = []
      index += 1
      while (index < lines.length && !(lines[index] ?? '').startsWith('```')) {
        code.push(lines[index] ?? '')
        index += 1
      }
      if (index < lines.length) index += 1
      html.push('<pre><code>' + escapeHtml(code.join('\n')) + '</code></pre>')
      continue
    }
    if (isTableStart(lines, index)) {
      const tableLines: string[] = []
      tableLines.push(lines[index] ?? '')
      index += 1
      tableLines.push(lines[index] ?? '')
      index += 1
      while (index < lines.length && (lines[index] ?? '').includes('|') && !isRuleRow(lines[index] ?? '')) {
        tableLines.push(lines[index] ?? '')
        index += 1
      }
      html.push(renderTable(tableLines))
      continue
    }
    const heading = /^(#{1,6})\s+(.*)$/.exec(line)
    if (heading) {
      const level = (heading[1] ?? '#').length
      html.push('<h' + level + '>' + renderInline(heading[2] ?? '') + '</h' + level + '>')
      index += 1
      continue
    }
    if (/^\s*[-*]\s+/.test(line)) {
      const items: string[] = []
      while (index < lines.length && /^\s*[-*]\s+/.test(lines[index] ?? '')) {
        items.push('<li>' + renderInline((lines[index] ?? '').replace(/^\s*[-*]\s+/, '')) + '</li>')
        index += 1
      }
      html.push('<ul>' + items.join('') + '</ul>')
      continue
    }
    if (/^\s*\d+\.\s+/.test(line)) {
      const items: string[] = []
      while (index < lines.length && /^\s*\d+\.\s+/.test(lines[index] ?? '')) {
        items.push('<li>' + renderInline((lines[index] ?? '').replace(/^\s*\d+\.\s+/, '')) + '</li>')
        index += 1
      }
      html.push('<ol>' + items.join('') + '</ol>')
      continue
    }
    if (line.trim() === '') {
      index += 1
      continue
    }
    const paragraph: string[] = [line]
    index += 1
    while (index < lines.length && (lines[index] ?? '').trim() !== '' && !isBlockLine(lines[index] ?? '')) {
      paragraph.push(lines[index] ?? '')
      index += 1
    }
    html.push('<p>' + renderInline(paragraph.join('\n')) + '</p>')
  }
  return html.join('')
}
