import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  canRetryFailedInstall,
  canWithdrawInstall,
  cleanupDialogTitle,
  describeCleanupImpact,
  describeFailedDetail,
  describeInterruptDetail,
  describePluginStatus,
  describeWithdrawEffect,
  withdrawConfirmTitle,
  withdrawSuccessText
} from './plugin-status.ts'

describe('等待依赖安装的撤回', () => {
  it('停在等待依赖时可以撤回，文案说明会删掉文件、数据表和菜单', () => {
    const row = { state: 4, npm_dependent_wait_install: 1 }
    assert.equal(canWithdrawInstall(row), true)
    assert.equal(describePluginStatus(row), '还差依赖没装，按上面的命令装好后点刷新')
    assert.equal(withdrawConfirmTitle('验收插件'), '撤回「验收插件」的安装？')
    assert.match(describeWithdrawEffect(), /插件文件/)
    assert.match(describeWithdrawEffect(), /数据表和菜单/)
    assert.equal(withdrawSuccessText('验收插件'), '已撤回「验收插件」的安装')
  })

  it('安装中断可以续做或撤回，状态提示不再指回详情', () => {
    const row = { state: 4, dependency_command_nonce: 'n1' }
    assert.equal(canWithdrawInstall(row), true)
    assert.equal(canRetryFailedInstall(row), false)
    assert.equal(canWithdrawInstall({ state: 1 }), false)
    assert.equal(
      describePluginStatus(row),
      '上次安装到一半停了。按上方命令检查后，点「一键续做」或「撤回安装」'
    )
    assert.match(describeInterruptDetail(), /一键续做/)
    assert.match(describeInterruptDetail(), /撤回安装/)
    assert.equal(describeInterruptDetail().includes('查看详情'), false)
  })

  it('安装失败可以重新安装或撤回', () => {
    assert.equal(describePluginStatus({ state: 8 }), '安装没有完成。可以点「重新安装」，或点「撤回安装」清掉')
    assert.equal(canWithdrawInstall({ state: 8 }), true)
    assert.equal(canRetryFailedInstall({ state: 8 }), true)
    assert.equal(canRetryFailedInstall({ state: 8, cleanup_pending: true }), false)
    const detail = describeFailedDetail('依赖没有装完')
    assert.match(detail, /安装没有完成：依赖没有装完/)
    assert.match(detail, /重新安装/)
    assert.match(detail, /撤回安装/)
  })

  it('撤回说明拆成两句，清理说明按数量写', () => {
    const effect = describeWithdrawEffect()
    assert.equal(effect.includes('。'), true)
    assert.match(effect, /不影响其他插件。删掉的数据没法恢复/)
    assert.equal(cleanupDialogTitle('示例插件'), '清理「示例插件」的残留')
    assert.equal(
      describeCleanupImpact(0, 0),
      '没有留下数据表和菜单，清理只会把插件文件另存一份再移走。'
    )
    assert.match(describeCleanupImpact(2, 1), /2 张数据表/)
    assert.match(describeCleanupImpact(2, 1), /1 个菜单/)
  })
})
