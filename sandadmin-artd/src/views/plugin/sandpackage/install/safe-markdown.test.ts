import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { renderPlainMarkdown } from './safe-markdown.ts'

describe('renderPlainMarkdown', () => {
  it('标题、列表、表格和代码块会排版，原文标记不留在结果里', () => {
    const html = renderPlainMarkdown(
      ['# 安装说明', '', '- 第一步', '', '| 项目 | 说明 |', '| --- | --- |', '| 端口 | 只给本机 |', '', '```', 'npm run dev', '```'].join(
        '\n'
      )
    )
    assert.match(html, /<h1>安装说明<\/h1>/)
    assert.match(html, /<li>第一步<\/li>/)
    assert.match(html, /<th>项目<\/th>/)
    assert.match(html, /<td>只给本机<\/td>/)
    assert.match(html, /<code>npm run dev<\/code>/)
    assert.equal(html.includes('#'), false)
    assert.equal(html.includes('|'), false)
  })

  it('HTML 和脚本只当文字，不会变成可执行标签', () => {
    const html = renderPlainMarkdown('<script>alert(1)</script>\n<img src=x onerror=alert(1)>')
    assert.equal(/<script/i.test(html), false)
    assert.equal(/<img/i.test(html), false)
    assert.match(html, /&lt;script&gt;/)
    assert.match(html, /&lt;img/)
  })
})
