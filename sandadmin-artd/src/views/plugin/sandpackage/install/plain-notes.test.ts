import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { stripCodedNotes } from './plain-notes.ts'

describe('stripCodedNotes', () => {
  it('含标识和代码的句子不进页面', () => {
    const notes =
      '这次修了上传失败时的提示。调用 showErrorMessage 并读取 .env。方法里有 method_exists() 和 SQL。'
    const plain = stripCodedNotes(notes)
    assert.equal(plain.includes('showErrorMessage'), false)
    assert.equal(plain.includes('.env'), false)
    assert.equal(plain.includes('method_exists'), false)
    assert.equal(plain.includes('SQL'), false)
    assert.match(plain, /这次修了上传失败时的提示/)
  })

  it('整段都是代码时改用白话占位', () => {
    assert.equal(
      stripCodedNotes('ux_p2b showErrorMessage `.env`'),
      '该版本尚未提供更新摘要，可查看包内更新日志。'
    )
  })
})
