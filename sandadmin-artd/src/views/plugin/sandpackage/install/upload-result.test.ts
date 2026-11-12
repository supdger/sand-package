import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { INVALID_PACKAGE_MESSAGE, describeUploadFailure } from './upload-result.ts'

describe('describeUploadFailure', () => {
  it('包体无效时页面只显示白话，细节留给复制', () => {
    const error = Object.assign(new Error('插件包缺少 info.ini 或 info.ini 内容无法解析'), {
      code: 422
    })
    const text = describeUploadFailure(error)
    assert.equal(text.title, INVALID_PACKAGE_MESSAGE)
    assert.equal(text.detail, '插件包缺少 info.ini 或 info.ini 内容无法解析')
  })

  it('HTTP 层 422 不算包体无效', () => {
    const error = Object.assign(new Error('参数错误'), { code: 422, transport: 'http' })
    const text = describeUploadFailure(error)
    assert.equal(text.title, '参数错误')
    assert.equal(text.detail, '参数错误')
  })

  it('业务拒绝保留后端原始文案', () => {
    const error = Object.assign(new Error('升级包版本必须高于已安装版本'), { code: 400 })
    const text = describeUploadFailure(error)
    assert.equal(text.title, '升级包版本必须高于已安装版本')
    assert.equal(text.detail, '升级包版本必须高于已安装版本')
  })

  it('无消息错误退回重试话术', () => {
    assert.equal(describeUploadFailure(null).title, '上传没有完成，请再试一次')
    assert.equal(describeUploadFailure(new Error('')).title, '上传没有完成，请再试一次')
  })
})
