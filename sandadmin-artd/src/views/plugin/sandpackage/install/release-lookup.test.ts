import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { describeRequestFailure, isReleaseReadFailed, readReleaseFailure } from './release-lookup.ts'

describe('isReleaseReadFailed', () => {
  it('官方发行返回 403 时算读取失败，技术原因只留在详情里', () => {
    const checks = [
      { code: 'repository', state: 'fail', message: '官方发行读取失败（HTTP 403）' },
      { code: 'php', state: 'ok', message: '通过' }
    ]
    assert.equal(isReleaseReadFailed(checks, ''), true)
    assert.match(readReleaseFailure(checks), /HTTP 403/)
  })

  it('请求超时也算读取失败', () => {
    assert.equal(isReleaseReadFailed(null, 'timeout of 65000ms exceeded'), true)
  })

  it('读成功且没有失败项时不算读取失败', () => {
    assert.equal(isReleaseReadFailed([{ code: 'php', state: 'ok', message: '通过' }], ''), false)
  })

  it('复制信息带上 HTTP 状态，超时单独写成请求超时', () => {
    assert.equal(describeRequestFailure('禁止访问该资源', 403, false), '禁止访问该资源（HTTP 403）')
    assert.equal(describeRequestFailure('请求超时', 0, true), '请求超时')
  })
})
