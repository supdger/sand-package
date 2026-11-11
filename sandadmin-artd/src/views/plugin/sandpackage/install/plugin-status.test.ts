import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  canWithdrawInstall,
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

  it('命令中断或已经装好时不出现撤回', () => {
    assert.equal(canWithdrawInstall({ state: 4, dependency_command_nonce: 'n1' }), false)
    assert.equal(canWithdrawInstall({ state: 1 }), false)
    assert.equal(
      describePluginStatus({ state: 4, dependency_command_nonce: 'n1' }),
      '安装中断了，点「查看详情」，按里面的说明处理'
    )
  })

  it('失败时提示去查看详情，不把提示塞进按钮文字', () => {
    assert.equal(describePluginStatus({ state: 8 }), '安装没有完成，点「查看详情」看原因')
    assert.equal(canWithdrawInstall({ state: 8 }), false)
  })
})
