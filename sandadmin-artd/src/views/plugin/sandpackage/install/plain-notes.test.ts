import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { describeReleaseNotes, stripCodedNotes } from './plain-notes.ts'

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

describe('describeReleaseNotes', () => {
  it('核验套话不进弹窗，没有白话时写占位句', () => {
    const notes =
      '访问矩阵补 showErrorMessage:false，宿主 toast 不再直出英文原文。无新增数据库迁移，install/update/uninstall SQL 与 0.9.4 逐字节一致；构建 1055 文件/条目一致、独立双阶段构建位一致；本次未执行安装/升级 SQL 或重跑业务验收。'
    assert.equal(describeReleaseNotes(notes), '这个版本没写更新说明')
  })

  it('白话句保留，核验句去掉', () => {
    const notes = '修复了上传失败时的提示。构建 1055 文件/条目一致。'
    assert.equal(describeReleaseNotes(notes), '修复了上传失败时的提示。')
  })
})
