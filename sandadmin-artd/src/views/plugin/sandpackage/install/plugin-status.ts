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

/** 插件状态格下面那一行：说清下一步，不把人指回详情。 */
export function describePluginStatus(row: PluginStatusRow): string {
  if (row.cleanup_pending === true) return '上次清理还没做完，点「继续清理」'
  if (isInstallInterrupted(row)) {
    return '上次安装到一半停了。按上方命令检查后，点「一键续做」或「撤回安装」'
  }
  if (
    row.state === 4 ||
    row.npm_dependent_wait_install === 1 ||
    row.composer_dependent_wait_install === 1
  ) {
    return '还差依赖没装，按上面的命令装好后点刷新'
  }
  if (row.state === 8) return '安装没有完成。可以点「重新安装」，或点「撤回安装」清掉'
  if (row.state === 7) return '插件文件找不到了，点「清理残留」后再安装'
  if (row.state === 5) return '安装目录正被占用，请稍后点刷新'
  if (row.state === 6) return '已找到插件文件，点「登记现有插件」完成登记'
  if (row.state === 3) return '依赖有冲突，点「查看详情」按说明处理'
  return ''
}

export function canWithdrawInstall(row: PluginStatusRow): boolean {
  if (row.cleanup_pending === true) return false
  if (isInstallInterrupted(row) || row.state === 8) return true
  return row.state === 4 && row.ordinary_actions_blocked !== true
}

export function canRetryFailedInstall(row: PluginStatusRow): boolean {
  return row.state === 8 && row.cleanup_pending !== true && !isInstallInterrupted(row)
}

export function canResumeInstall(row: PluginStatusRow): boolean {
  if (row.cleanup_pending === true) return false
  return (
    isInstallInterrupted(row) ||
    row.state === 4 ||
    row.npm_dependent_wait_install === 1 ||
    row.composer_dependent_wait_install === 1
  )
}

export function describeInterruptDetail(): string {
  return '上次安装到一半停了。先按页面上方的命令检查一下，再点「一键续做」接着装；不想装了可以点「撤回安装」。'
}

export function describeWaitingDetail(): string {
  return '还差依赖没装完。点「一键续做」；如果依赖还没齐，页面会写出还差哪个，并给出要复制的命令。装好后点「刷新」。'
}

export function describeFailedDetail(reason: string): string {
  const plain = reason.trim() === '' ? '没有写出具体原因' : reason.trim()
  return `安装没有完成：${plain}。可以点「重新安装」再试，或者点「撤回安装」把装了一半的东西清掉。`
}

export function cleanupDialogTitle(name: string): string {
  return `清理「${name}」的残留`
}

export function describeCleanupFold(tableCount: number, menuCount: number): string {
  return `${tableCount} 个数据表、${menuCount} 个菜单、插件文件会另存到备份目录`
}

export interface CleanupProblemInput {
  tables: string[]
  menus: { name: string; code: string }[]
  paths: string[]
}

export function cleanupProblemText(input: CleanupProblemInput): string {
  const tables = input.tables.length > 0 ? input.tables.join('\n') : '没有数据表'
  const menus =
    input.menus.length > 0
      ? input.menus.map((menu) => `${menu.name} ${menu.code}`).join('\n')
      : '没有菜单'
  const paths = input.paths.length > 0 ? input.paths.join('\n') : '没有插件文件'
  return `数据表\n${tables}\n菜单\n${menus}\n插件文件\n${paths}`
}

export interface RowActionItem {
  label: string
}

export function packRowActions<T extends RowActionItem>(actions: T[]): { shown: T[]; extra: T[] } {
  const seen = new Set<string>()
  const unique: T[] = []
  for (const action of actions) {
    if (seen.has(action.label)) continue
    seen.add(action.label)
    unique.push(action)
  }
  return { shown: unique.slice(0, 2), extra: unique.slice(2) }
}

export function describeCleanupImpact(tableCount: number, menuCount: number): string {
  if (tableCount === 0 && menuCount === 0) {
    return '没有留下数据表和菜单，清理只会把插件文件另存一份再移走。'
  }
  return `会删掉这个插件留下的 ${tableCount} 张数据表和 ${menuCount} 个菜单，插件文件会先另存一份；删掉的数据没法恢复。`
}

export function withdrawConfirmTitle(name: string): string {
  return `撤回「${name}」的安装？`
}

export function describeWithdrawEffect(): string {
  return '撤回后会删掉已经放上去的插件文件，还有这次安装写进去的数据表和菜单，不影响其他插件。删掉的数据没法恢复，以后想用可以重新安装。'
}

export function withdrawSuccessText(name: string): string {
  return `已撤回「${name}」的安装`
}
