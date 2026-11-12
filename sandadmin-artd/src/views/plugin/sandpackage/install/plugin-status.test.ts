import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  canResumeInstall,
  canRetryFailedInstall,
  canWithdrawInstall,
  cleanupDialogTitle,
  cleanupProblemText,
  describeCleanupFold,
  describeCleanupImpact,
  describeWaitingDetail,
  packRowActions,
  describeFailedDetail,
  describeFailedReason,
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
    const detail = describeFailedDetail()
    assert.equal(
      detail,
      '安装没有完成。可以点「重新安装」再试，或者点「撤回安装」把装了一半的东西清掉。'
    )
  })

  it('失败原因去掉把人指回本页的旧说法', () => {
    assert.equal(
      describeFailedReason('插件上次安装操作失败，请从已安装插件管理页恢复。'),
      '插件上次安装操作失败'
    )
    assert.equal(describeFailedReason('请从已安装插件管理页继续。'), '没有写出具体原因')
    assert.equal(describeFailedReason(''), '没有写出具体原因')
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

  it('等待依赖的说明指出要点「一键续做」', () => {
    assert.match(describeWaitingDetail(), /一键续做/)
    assert.equal(describeWaitingDetail().includes('已安装插件管理页'), false)
    assert.equal(canResumeInstall({ state: 4 }), true)
    assert.equal(canResumeInstall({ state: 8 }), false)
  })

  it('清理展开只用数量，表名和路径放进复制文本', () => {
    assert.equal(describeCleanupFold(2, 1), '2 个数据表、1 个菜单、插件文件会另存到备份目录')
    const copied = cleanupProblemText({
      tables: ['public.sand_ca_residue_item'],
      menus: [{ name: '菜单', code: 'ca-residue:list' }],
      paths: ['/tmp/ca-residue']
    })
    assert.match(copied, /sand_ca_residue_item/)
    assert.match(copied, /\/tmp\/ca-residue/)
    assert.equal(describeCleanupFold(2, 1).includes('sand_ca_residue_item'), false)
  })

  it('同一行同名按钮只留一个，第三个起放进更多', () => {
    const packed = packRowActions([
      { label: '一键续做' },
      { label: '一键续做' },
      { label: '撤回安装' },
      { label: '查看详情' }
    ])
    assert.deepEqual(
      packed.shown.map((item) => item.label),
      ['一键续做', '撤回安装']
    )
    assert.deepEqual(
      packed.extra.map((item) => item.label),
      ['查看详情']
    )
  })
})
