import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  describeEnvironmentUnsupported,
  describeUpdateProblem,
  isEnvironmentUnsupported,
  readEnvironmentFailure
} from './update-environment.ts'

describe('describeEnvironmentUnsupported', () => {
  it('没有新版本时说明不用做什么', () => {
    assert.equal(
      describeEnvironmentUnsupported([]),
      '这台服务器不支持在网页里升级。现在已经是最新版，不用做什么；以后有新版本时，请让技术人员按升级说明手动升级。'
    )
  })

  it('有新版本时带接口返回的版本号', () => {
    assert.equal(
      describeEnvironmentUnsupported(['0.2.26']),
      '有新版本 0.2.26。这台服务器不支持在网页里升级，请让技术人员按升级说明手动升级。'
    )
  })

  it('多个组件各有新版本时全部列出', () => {
    assert.match(describeEnvironmentUnsupported(['0.3.0', '0.2.26']), /^有新版本 0\.3\.0、0\.2\.26。/)
  })
})

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
    assert.ok(!detail.includes(describeEnvironmentUnsupported([])))
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
