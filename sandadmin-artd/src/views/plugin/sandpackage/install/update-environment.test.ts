import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  ENVIRONMENT_UNSUPPORTED_MESSAGE,
  describeUpdateProblem,
  isEnvironmentUnsupported,
  readEnvironmentFailure
} from './update-environment.ts'

describe('isEnvironmentUnsupported', () => {
  it('环境检查失败算不支持，官方发行读取失败不算', () => {
    assert.equal(isEnvironmentUnsupported([{ code: 'deployment', state: 'fail', message: '更新路径不能经过符号链接' }]), true)
    assert.equal(isEnvironmentUnsupported([{ code: 'repository', state: 'fail', message: '官方发行读取失败（HTTP 403）' }]), false)
    assert.equal(isEnvironmentUnsupported(null), false)
    assert.equal(isEnvironmentUnsupported([{ code: 'php', state: 'ok', message: '通过' }]), false)
  })
})

describe('readEnvironmentFailure', () => {
  it('只汇总非发行项的失败原因，通过项不进入', () => {
    const checks = [
      { code: 'deployment', state: 'fail', message: '更新路径不能经过符号链接' },
      { code: 'repository', state: 'fail', message: '官方发行读取失败（HTTP 403）' },
      { code: 'php', state: 'ok', message: '通过' }
    ]
    assert.equal(readEnvironmentFailure(checks), '更新路径不能经过符号链接')
  })
})

describe('describeUpdateProblem', () => {
  it('环境不支持时技术细节只进复制内容，白话不进详情', () => {
    const detail = describeUpdateProblem([{ code: 'deployment', state: 'fail', message: '更新路径不能经过符号链接' }], '')
    assert.match(detail, /符号链接/)
    assert.ok(!detail.includes(ENVIRONMENT_UNSUPPORTED_MESSAGE))
  })

  it('环境失败与发行读取失败合并复制', () => {
    const detail = describeUpdateProblem([
      { code: 'deployment', state: 'fail', message: '更新路径不能经过符号链接' },
      { code: 'repository', state: 'fail', message: '官方发行读取失败（HTTP 403）' }
    ], '')
    assert.equal(detail, '更新路径不能经过符号链接；官方发行读取失败（HTTP 403）')
  })

  it('请求级失败优先于检查项', () => {
    const detail = describeUpdateProblem([{ code: 'deployment', state: 'fail', message: '更新路径不能经过符号链接' }], '禁止访问该资源（HTTP 403）')
    assert.equal(detail, '禁止访问该资源（HTTP 403）')
  })

  it('环境支持时复制内容退化为发行读取细节', () => {
    assert.equal(describeUpdateProblem([{ code: 'repository', state: 'fail', message: '官方发行读取失败（HTTP 403）' }], ''), '官方发行读取失败（HTTP 403）')
    assert.equal(describeUpdateProblem(null, ''), '')
  })
})
