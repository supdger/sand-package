export interface PluginStatusRow {
  state: number
  npm_dependent_wait_install?: number
  composer_dependent_wait_install?: number
  dependency_command_nonce?: string
  dependency_failed_nonce?: string
  process_recovery_required?: number
  cleanup_pending?: boolean
  ordinary_actions_blocked?: boolean
}

export function isInstallInterrupted(row: PluginStatusRow): boolean {
  return (
    (row.dependency_command_nonce ?? '') !== '' ||
    (row.dependency_failed_nonce ?? '') !== '' ||
    row.process_recovery_required === 1
  )
}

/** 插件状态格下面那一行：说清下一步，不放进操作列。 */
export function describePluginStatus(row: PluginStatusRow): string {
  if (row.cleanup_pending === true) return '上次清理还没做完，点「继续清理」'
  if (isInstallInterrupted(row)) return '安装中断了，点「查看详情」，按里面的说明处理'
  if (
    row.state === 4 ||
    row.npm_dependent_wait_install === 1 ||
    row.composer_dependent_wait_install === 1
  ) {
    return '还差依赖没装，按上面的命令装好后点刷新'
  }
  if (row.state === 8) return '安装没有完成，点「查看详情」看原因'
  if (row.state === 7) return '插件文件找不到了，点「清理残留」后再安装'
  if (row.state === 5) return '安装目录正被占用，请稍后点刷新'
  if (row.state === 6) return '已找到插件文件，点「登记现有插件」完成登记'
  if (row.state === 3) return '依赖有冲突，点「查看详情」按说明处理'
  return ''
}

export function canWithdrawInstall(row: PluginStatusRow): boolean {
  return (
    row.state === 4 &&
    !isInstallInterrupted(row) &&
    row.ordinary_actions_blocked !== true &&
    row.cleanup_pending !== true
  )
}

export function withdrawConfirmTitle(name: string): string {
  return `撤回「${name}」的安装？`
}

export function describeWithdrawEffect(): string {
  return '撤回后会删掉已经放上去的插件文件、这次安装写入的数据表和菜单，不影响其他插件，删掉的数据没法恢复，以后想用可以重新安装。'
}

export function withdrawSuccessText(name: string): string {
  return `已撤回「${name}」的安装`
}
