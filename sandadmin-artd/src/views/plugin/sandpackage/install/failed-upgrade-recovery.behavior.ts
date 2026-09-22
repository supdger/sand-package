/**
 * 挂载真实 index.vue 的行为检查：replace → refresh → retry，以及刷新失败后的可观察回退。
 */
import { nextTick } from 'vue'
import {
  FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE,
  FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE,
  FAILED_UPGRADE_VERIFIED_MESSAGE,
  FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE,
  FAILED_UPGRADE_ZIP_MAX_BYTES,
  assertFailedUpgradeZip,
  createFailedUpgradeSession,
  exactReplaceConfirmation,
  exactRetryConfirmation,
  humanizeRecoveryMessage,
  isDurableRetryReadyRow,
  isFailedUpgradeRecovery,
  parseRuntimeRestoreResult,
  parseInspectionResult,
  parsePrepareResult,
  restoreRuntimeFromBackup,
  parseVerifyResult,
  reconcileFailedUpgradeSession,
  replaceConfirmationText,
  shouldHideGlobalPluginWrites,
  type FailedUpgradeRecoveryPhase,
  type FailedUpgradeRecoverySession,
  type SandpackageInstallRow
} from './failed-upgrade-recovery'
import {
  cleanupHttpCounts,
  configureCleanupSubmitFailureOnce,
  configureRecoveryHttpMock,
  configureRuntimeRestoreRefreshFixture,
  currentRecoveryPostUrl
} from './failed-upgrade-recovery.http-mock'
import { mountSandpackageInstallPage } from './failed-upgrade-recovery.index-mount'

function textOf(el: Node | null): string {
  return el?.textContent?.replace(/\s+/g, ' ').trim() ?? ''
}

function buttonsOf(root: ParentNode): HTMLButtonElement[] {
  return Array.from(root.querySelectorAll('button'))
}

function buttonByText(root: ParentNode, label: string): HTMLButtonElement | null {
  for (const button of buttonsOf(root)) {
    if (textOf(button).includes(label)) return button
  }
  return null
}

function controlByText(root: ParentNode, label: string): HTMLElement | null {
  for (const control of Array.from(root.querySelectorAll('button, a'))) {
    if (textOf(control).includes(label) && control instanceof HTMLElement) return control
  }
  return null
}

function cleanupDialog(): HTMLElement | null {
  const dialogs = Array.from(document.querySelectorAll('.el-dialog'))
  for (let index = dialogs.length - 1; index >= 0; index -= 1) {
    const dialog = dialogs[index]
    if (dialog instanceof HTMLElement && textOf(dialog).includes('清理异常插件残留')) {
      return dialog
    }
  }
  return null
}

function recoveryDrawer(): HTMLElement | null {
  const drawers = Array.from(document.querySelectorAll('.el-drawer.open'))
  const drawer = drawers.at(-1)
  return drawer instanceof HTMLElement ? drawer : null
}

function sessionPhase(session: FailedUpgradeRecoverySession): FailedUpgradeRecoveryPhase {
  return session.phase
}

function expectTrue(condition: boolean, label: string): void {
  if (!condition) throw new Error(label)
}

/** Vue/Element Plus 需要原生 value setter，仅改 DOM 属性不会更新 v-model。 */
function setNativeInputValue(input: HTMLInputElement, value: string): void {
  const descriptor = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')
  descriptor?.set?.call(input, value)
  input.dispatchEvent(new Event('input', { bubbles: true, composed: true }))
  input.dispatchEvent(new Event('change', { bubbles: true }))
}

async function assignZipFile(root: ParentNode, zip: File): Promise<void> {
  await waitFor(() => root.querySelector('input[type="file"]') !== null, 'zip input rendered')
  const fileInput = root.querySelector('input[type="file"]')
  if (!(fileInput instanceof HTMLInputElement)) throw new Error('zip input missing')
  const transfer = new DataTransfer()
  transfer.items.add(zip)
  fileInput.files = transfer.files
  fileInput.dispatchEvent(new Event('change', { bubbles: true }))
  fileInput.dispatchEvent(new Event('input', { bubbles: true }))
}

function exactFailedRow(overrides: Partial<SandpackageInstallRow> = {}): SandpackageInstallRow {
  return {
    app: 'sample-plugin',
    title: 'Sample Plugin',
    about: 'identity service',
    author: 'sand',
    version: '0.7.0',
    state: 8,
    stage: 'failed',
    failed_stage: 'database_update',
    update: 1,
    upgrade_from_version: '0.6.0',
    recovery_mode: 'verification_required',
    ...overrides
  }
}

function exactReplacementMarkers(): Pick<
  SandpackageInstallRow,
  | 'candidate_archive_sha256'
  | 'candidate_payload_manifest_sha256'
  | 'recovery_descriptor_sha256'
  | 'update_sql_sha256'
  | 'failed_upgrade_replacement_id'
  | 'replacement_candidate_state'
> {
  return {
    candidate_archive_sha256: 'a'.repeat(64),
    candidate_payload_manifest_sha256: 'b'.repeat(64),
    recovery_descriptor_sha256: 'c'.repeat(64),
    update_sql_sha256: 'd'.repeat(64),
    failed_upgrade_replacement_id: 'e'.repeat(32),
    replacement_candidate_state: 'ready'
  }
}

async function waitFor(predicate: () => boolean, label: string, tries = 80): Promise<void> {
  for (let index = 0; index < tries; index += 1) {
    await nextTick()
    if (predicate()) return
    await new Promise((resolve) => {
      window.setTimeout(resolve, 50)
    })
  }
  throw new Error(`timeout: ${label}`)
}

async function confirmPrompt(root: ParentNode, value: string): Promise<void> {
  await waitFor(() => root.querySelector('.el-message-box') !== null, 'message box')
  const messageBoxes = Array.from(root.querySelectorAll('.el-message-box'))
  const messageBox = messageBoxes.at(-1)
  if (!(messageBox instanceof HTMLElement)) throw new Error('message box missing')
  const input = messageBox.querySelector('input')
  if (!(input instanceof HTMLInputElement)) throw new Error('confirmation input missing')
  setNativeInputValue(input, value)
  await waitFor(() => input.value === value, 'confirmation value applied')
  await nextTick()
  const confirm =
    buttonByText(messageBox, '确认替换') ??
    buttonByText(messageBox, '确认恢复') ??
    buttonByText(messageBox, '确认升级') ??
    buttonByText(messageBox, '确定')
  if (!confirm) throw new Error('confirmation button missing')
  confirm.click()
}

async function openRecoveryDetail(root: ParentNode): Promise<void> {
  await waitFor(() => controlByText(root, '恢复处理') !== null, 'recovery entry rendered')
  controlByText(root, '恢复处理')?.click()
  await waitFor(
    () => document.querySelector('.el-drawer') !== null,
    'recovery detail drawer opened'
  )
}

export async function runFailedUpgradeRecoveryBehaviorHarness(): Promise<string[]> {
  const passed: string[] = []
  const expectedTuple = { app: 'sample-plugin', fromVersion: '0.6.0', toVersion: '0.7.0' }

  const ordinaryBlockedRows = [5, 6, 7].map(
    (state): SandpackageInstallRow => ({
      app: `ordinary-${state}`,
      title: `Ordinary ${state}`,
      about: 'ordinary blocked state',
      author: 'sand',
      version: '0.6.0',
      state,
      ordinary_actions_blocked: true
    })
  )
  for (const row of ordinaryBlockedRows) {
    expectTrue(
      !isFailedUpgradeRecovery(row),
      `ordinary blocked state ${row.state} is not a failed database upgrade`
    )
  }
  const exactRecovery = exactFailedRow({ recovery_mode: 'none' })
  expectTrue(isFailedUpgradeRecovery(exactRecovery), 'exact failed-upgrade shape remains recovery')
  for (const recoveryMode of [
    'verification_required',
    'runtime_restore_required',
    'retry_safe',
    'blocked'
  ] as const) {
    expectTrue(
      isFailedUpgradeRecovery({ recovery_mode: recoveryMode }),
      `explicit recovery mode remains recovery: ${recoveryMode}`
    )
  }
  expectTrue(
    !shouldHideGlobalPluginWrites(ordinaryBlockedRows, {}),
    'ordinary blocked rows do not close unrelated global writes'
  )
  expectTrue(
    shouldHideGlobalPluginWrites([exactRecovery], {}),
    'actual failed upgrade closes global writes'
  )
  passed.push('ordinary blocked states stay separate from actual failed-upgrade recovery')

  const v2Inspection = parseInspectionResult(
    {
      app: 'sample-plugin',
      from_version: '0.6.0',
      to_version: '0.7.0',
      recovery_mode: 'verification_required',
      allowed_actions: ['prepare_failed_upgrade_replacement'],
      message: '恢复状态检查完成'
    },
    expectedTuple
  )
  expectTrue(
    !v2Inspection.runtimeDrift,
    'v2 inspection does not require a fabricated runtime field'
  )
  const v2RuntimeInspection = parseInspectionResult(
    {
      app: 'sample-plugin',
      from_version: '0.6.0',
      to_version: '0.7.0',
      recovery_mode: 'runtime_restore_required',
      runtime_drift: true,
      allowed_actions: ['restore_runtime_from_backup'],
      message: '需要恢复运行文件'
    },
    expectedTuple
  )
  expectTrue(
    v2RuntimeInspection.runtimeDrift,
    'explicit runtime restore DTO opens the restore path'
  )
  for (const runtimeDrift of [undefined, 'true']) {
    try {
      parseInspectionResult(
        {
          app: 'sample-plugin',
          from_version: '0.6.0',
          to_version: '0.7.0',
          recovery_mode: 'runtime_restore_required',
          runtime_drift: runtimeDrift,
          allowed_actions: ['restore_runtime_from_backup']
        },
        expectedTuple
      )
      throw new Error('runtime restore without a boolean drift must fail')
    } catch (error: unknown) {
      if (!(error instanceof Error) || error.message !== FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE) {
        throw error
      }
    }
  }
  passed.push('v2 inspect DTO uses exact mode/action and explicit runtime drift only when required')

  for (const payload of [
    {
      app: 'sample-plugin',
      from_version: '0.6.0',
      replacement_id: 'rep-1',
      profile_hash: 'f'.repeat(64)
    },
    {
      app: 'sample-plugin',
      from_version: '0.6.0',
      to_version: '0.7.1',
      replacement_id: 'rep-1',
      profile_hash: 'f'.repeat(64)
    }
  ]) {
    try {
      parsePrepareResult(payload, expectedTuple)
      throw new Error('prepare tuple mismatch must fail')
    } catch (error: unknown) {
      if (!(error instanceof Error) || error.message !== FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE) {
        throw error
      }
    }
  }
  passed.push('prepare requires the complete matching failed-upgrade tuple')

  try {
    parseVerifyResult(
      {
        app: 'sample-plugin',
        from_version: '0.6.0',
        to_version: '0.7.0',
        message: FAILED_UPGRADE_VERIFIED_MESSAGE
      },
      { app: 'sample-plugin', fromVersion: '0.6.0', toVersion: '0.7.0' },
      'rep-1',
      'f'.repeat(64)
    )
    throw new Error('missing verdict must fail')
  } catch (error: unknown) {
    if (!(error instanceof Error) || error.message !== FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE) {
      throw error
    }
  }
  const prepareOnlyVerify = parseVerifyResult(
    {
      app: 'sample-plugin',
      from_version: '0.6.0',
      to_version: '0.7.0',
      verdict: 'retry_safe',
      replacement_id: 'rep-1',
      profile_hash: 'f'.repeat(64),
      evidence_fingerprint: 'e'.repeat(64),
      assertions_total: 101,
      assertions_passed: 101,
      failed_assertion_ids: [],
      audit_written: false,
      allowed_actions: ['replace_failed_upgrade_candidate']
    },
    { app: 'sample-plugin', fromVersion: '0.6.0', toVersion: '0.7.0' },
    'rep-1',
    'f'.repeat(64)
  )
  expectTrue(
    prepareOnlyVerify.app === 'sample-plugin',
    'Gate A accepts matching sealed replacement'
  )
  try {
    parseVerifyResult(
      {
        app: 'sample-plugin',
        from_version: '0.6.0',
        to_version: '0.7.0',
        verdict: 'retry_safe',
        replacement_id: 'rep-1',
        profile_hash: 'f'.repeat(64),
        evidence_fingerprint: 'e'.repeat(64),
        assertions_total: 101,
        assertions_passed: 101,
        failed_assertion_ids: [],
        audit_written: true,
        allowed_actions: ['replace_failed_upgrade_candidate']
      },
      { app: 'sample-plugin', fromVersion: '0.6.0', toVersion: '0.7.0' },
      'rep-1',
      'f'.repeat(64)
    )
    throw new Error('Gate A audit write must fail')
  } catch (error: unknown) {
    if (!(error instanceof Error) || error.message !== FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE) {
      throw error
    }
  }
  passed.push('Gate A requires complete read-only assertion evidence')
  try {
    parseVerifyResult(
      {
        app: 'sample-plugin',
        from_version: '0.6.0',
        to_version: '0.7.0',
        verdict: 'retry_safe',
        replacement_id: 'rep-1',
        profile_hash: 'f'.repeat(64),
        allowed_actions: ['replace_failed_upgrade_candidate', 'retry_after_replacement']
      },
      { app: 'sample-plugin', fromVersion: '0.6.0', toVersion: '0.7.0' },
      'rep-1',
      'f'.repeat(64)
    )
    throw new Error('verify retry action must fail')
  } catch (error: unknown) {
    if (!(error instanceof Error) || error.message !== FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE) {
      throw error
    }
  }
  try {
    parseVerifyResult(
      {
        app: 'other',
        from_version: '0.6.0',
        to_version: '0.7.0',
        verdict: 'retry_safe',
        replacement_id: 'other-rep',
        profile_hash: 'f'.repeat(64),
        allowed_actions: ['replace_failed_upgrade_candidate']
      },
      { app: 'sample-plugin', fromVersion: '0.6.0', toVersion: '0.7.0' },
      'rep-1',
      'f'.repeat(64)
    )
    throw new Error('mismatched app must fail')
  } catch (error: unknown) {
    if (!(error instanceof Error) || error.message !== FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE) {
      throw error
    }
  }
  try {
    replaceConfirmationText('sample-plugin', '0.7.0', 'REPLACE other@9.9.9')
    throw new Error('foreign REPLACE must fail')
  } catch (error: unknown) {
    if (!(error instanceof Error) || !error.message.includes('确认内容与当前应用版本不一致')) {
      throw error
    }
  }
  expectTrue(
    humanizeRecoveryMessage('SELECT * FROM pg_catalog.pg_class', 'fallback') === 'fallback',
    'sql hidden'
  )
  const zipOk = new File([new Uint8Array(8)], 'ok.zip', {
    type: 'application/zip'
  })
  const zipOver = new File([new Uint8Array(FAILED_UPGRADE_ZIP_MAX_BYTES + 1)], 'over.zip', {
    type: 'application/zip'
  })
  expectTrue(assertFailedUpgradeZip(zipOk) === null, 'small zip ok')
  expectTrue(assertFailedUpgradeZip(zipOver) === '插件包不能超过 5MB', 'oversize zip')

  const durable = exactFailedRow({
    ...exactReplacementMarkers(),
    recovery_mode: 'retry_safe',
    allowed_actions: ['retry_after_replacement']
  })
  expectTrue(isDurableRetryReadyRow(durable), 'durable retry shape')
  expectTrue(
    !isDurableRetryReadyRow(
      exactFailedRow({
        recovery_mode: 'retry_safe',
        ...exactReplacementMarkers(),
        allowed_actions: ['prepare_replacement', 'retry_after_replacement']
      })
    ),
    'verify-shaped retry_safe is not phase 3'
  )
  const session = createFailedUpgradeSession('sample-plugin', '0.6.0', '0.7.0')
  session.phase = 'replaced'
  reconcileFailedUpgradeSession(session, exactFailedRow())
  expectTrue(
    sessionPhase(session) === 'needs_verify',
    'missing retry_safe after refresh returns to verify'
  )
  passed.push('helper contracts remain fail-closed')

  const controllerEnvelope = {
    code: 0,
    message: '运行文件恢复完成',
    data: {
      result: {
        restore_id: 'f'.repeat(32),
        status: 'restored',
        runtime_manifest_hash: 'a'.repeat(64)
      },
      presentation: {
        recovery_mode: 'verification_required',
        allowed_actions: ['prepare_failed_upgrade_replacement']
      }
    }
  }
  const parsedRestore = parseRuntimeRestoreResult(controllerEnvelope)
  expectTrue(
    parsedRestore.status === 'restored' &&
      parsedRestore.presentation.recoveryMode === 'verification_required',
    'Controller envelope requires durable restore fields and fresh presentation'
  )
  const restored = await restoreRuntimeFromBackup({
    appName: 'sample-plugin',
    confirmation: 'RESTORE RUNTIME sample-plugin@0.6.0',
    expected: { app: 'sample-plugin', fromVersion: '0.6.0', toVersion: '0.7.0' }
  })
  expectTrue(
    restored.presentation.allowedActions[0] === 'prepare_failed_upgrade_replacement' &&
      currentRecoveryPostUrl() === '/app/sandpackage/install/restoreRuntimeFromBackup',
    'restore uses the established POST route and v2 durable presentation'
  )
  for (const malformed of [
    { code: 0, message: '运行文件恢复完成', data: {} },
    {
      code: 0,
      message: '运行文件恢复完成',
      data: {
        ...controllerEnvelope.data,
        presentation: {
          recovery_mode: 'verification_required'
        }
      }
    },
    {
      code: 0,
      message: '运行文件恢复完成',
      data: {
        ...controllerEnvelope.data,
        presentation: {
          recovery_mode: 'verification_required',
          allowed_actions: ['prepare_replacement']
        }
      }
    },
    {
      code: 0,
      message: '运行文件恢复完成',
      data: {
        ...controllerEnvelope.data,
        presentation: {
          recovery_mode: 'retry_safe',
          allowed_actions: ['retry_after_replacement']
        }
      }
    }
  ]) {
    try {
      parseRuntimeRestoreResult(malformed)
      throw new Error('missing or contradictory restore envelope must fail')
    } catch (error: unknown) {
      if (
        !(error instanceof Error) ||
        error.message !== FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE
      ) {
        throw error
      }
    }
  }
  passed.push('restore envelope rejects missing or contradictory durable evidence')

  configureRecoveryHttpMock('verification_required')
  const host = document.createElement('div')
  document.body.appendChild(host)
  const app = mountSandpackageInstallPage(host)
  await waitFor(() => controlByText(host, '恢复处理') !== null, 'install page rendered')
  expectTrue(
    !textOf(host).includes('检查恢复条件'),
    'recovery detail stays closed on initial render'
  )
  expectTrue(buttonByText(host, '上传插件包') === null, 'exact-failed hides upload')
  expectTrue(buttonByText(host, '重新执行升级') === null, 'retry hidden before diagnosis')
  await openRecoveryDetail(host)
  const inspectBtn = buttonByText(document, '检查恢复条件')
  expectTrue(inspectBtn !== null, 'diagnosis button present')
  inspectBtn?.click()
  await waitFor(() => buttonByText(document, '检查插件包') !== null, 'prepare after diagnosis')
  expectTrue(buttonByText(document, '重新执行升级') === null, 'diagnosis alone cannot expose retry')
  passed.push('mounted index.vue diagnosis opens only ZIP preflight')

  const zip = new File([new Uint8Array([0x50, 0x4b, 0x03, 0x04])], 'sample-plugin.zip', {
    type: 'application/zip'
  })
  await assignZipFile(document, zip)
  await waitFor(() => {
    const prepare = buttonByText(document, '检查插件包')
    return prepare !== null && !prepare.disabled
  }, 'prepare enabled after zip')
  buttonByText(document, '检查插件包')?.click()
  await waitFor(() => buttonByText(document, '核对插件包') !== null, 'verify after prepare')
  expectTrue(buttonByText(document, '恢复插件文件') === null, 'prepare cannot expose restore')
  buttonByText(document, '核对插件包')?.click()
  await waitFor(() => buttonByText(document, '恢复插件文件') !== null, 'restore after verify')
  expectTrue(buttonByText(document, '重新执行升级') === null, 'verify cannot expose retry')
  buttonByText(document, '恢复插件文件')?.click()
  await confirmPrompt(document, exactReplaceConfirmation('sample-plugin', '0.7.0'))
  await waitFor(
    () => buttonByText(document, '重新执行升级') !== null,
    'retry after restore refresh'
  )
  expectTrue(buttonByText(document, '恢复插件文件') === null, 'restore hidden on final step')
  passed.push('prepare then verify then restore refreshes into retry')

  configureRecoveryHttpMock('mismatched_retry_ready')
  buttonByText(document, '刷新状态')?.click()
  await waitFor(
    () =>
      buttonByText(document, '重新执行升级') === null &&
      buttonByText(document, '检查恢复条件') !== null,
    'mismatched refresh returns to diagnosis'
  )
  passed.push('mismatched durable tuple clears retry eligibility')

  for (const fixture of ['partial_modern_retry_safe', 'invalid_digest_retry_safe'] as const) {
    configureRecoveryHttpMock(fixture)
    buttonByText(document, '刷新状态')?.click()
    await waitFor(
      () =>
        buttonByText(document, '重新执行升级') === null &&
        buttonByText(document, '检查恢复条件') !== null,
      `${fixture} refresh clears retry`
    )
  }
  passed.push('partial or malformed modern list markers clear stale retry actions')

  configureRecoveryHttpMock('ordinary_blocked')
  buttonByText(document, '刷新状态')?.click()
  await waitFor(
    () =>
      controlByText(host, '查看详情') !== null && buttonByText(document, '检查恢复条件') === null,
    'ordinary blocked row closes recovery drawer'
  )
  expectTrue(textOf(host).includes('安装文件缺失'), 'state 7 uses the clear local label')
  expectTrue(
    textOf(host).includes('已找到安装记录，但未找到插件文件。'),
    'state 7 uses the short management explanation'
  )
  expectTrue(
    !textOf(host).includes('数据库升级未完成'),
    'ordinary blocked row does not claim a failed database upgrade'
  )
  passed.push('ordinary state 7 stays in plugin details and closes stale recovery UI')

  app.unmount()
  host.remove()

  configureRecoveryHttpMock('runtime_restore_required')
  const restoreHost = document.createElement('div')
  document.body.appendChild(restoreHost)
  const restoreApp = mountSandpackageInstallPage(restoreHost)
  await openRecoveryDetail(restoreHost)
  await waitFor(
    () => buttonByText(document, '恢复升级前文件') !== null,
    'runtime restore action rendered only for exact drift presentation'
  )
  for (const blockedAction of [
    '检查恢复条件',
    '检查插件包',
    '核对插件包',
    '恢复插件文件',
    '重新执行升级'
  ]) {
    expectTrue(
      buttonByText(document, blockedAction) === null,
      `runtime drift only permits restore: ${blockedAction}`
    )
  }
  buttonByText(document, '恢复升级前文件')?.click()
  await confirmPrompt(document, 'RESTORE RUNTIME sample-plugin@0.6.0')
  await waitFor(
    () => buttonByText(document, '检查插件包') !== null,
    'exact refreshed verification action advances runtime restore to ZIP preflight'
  )
  expectTrue(
    buttonByText(document, '检查恢复条件') === null &&
      buttonByText(document, '重新执行升级') === null,
    'restore refresh opens only needs_prepare'
  )
  passed.push('runtime restore refreshes exact list action into ZIP preflight only')
  restoreApp.unmount()
  restoreHost.remove()

  for (const refreshFixture of [
    'verification_required_missing_actions',
    'verification_required_wrong_actions'
  ] as const) {
    configureRecoveryHttpMock('runtime_restore_required')
    configureRuntimeRestoreRefreshFixture(refreshFixture)
    const malformedRestoreHost = document.createElement('div')
    document.body.appendChild(malformedRestoreHost)
    const malformedRestoreApp = mountSandpackageInstallPage(malformedRestoreHost)
    await openRecoveryDetail(malformedRestoreHost)
    await waitFor(
      () => buttonByText(document, '恢复升级前文件') !== null,
      `${refreshFixture} restore action rendered`
    )
    buttonByText(document, '恢复升级前文件')?.click()
    await confirmPrompt(document, 'RESTORE RUNTIME sample-plugin@0.6.0')
    await waitFor(
      () => buttonByText(document, '检查恢复条件') !== null,
      `${refreshFixture} refresh fails closed`
    )
    expectTrue(
      buttonByText(document, '检查插件包') === null &&
        buttonByText(document, '重新执行升级') === null,
      `${refreshFixture} cannot infer a write action`
    )
    malformedRestoreApp.unmount()
    malformedRestoreHost.remove()
  }
  passed.push('restore refresh missing or wrong list action fails closed')

  configureRecoveryHttpMock('retry_ready')
  const retryHost = document.createElement('div')
  document.body.appendChild(retryHost)
  const retryApp = mountSandpackageInstallPage(retryHost)
  await openRecoveryDetail(retryHost)
  await waitFor(() => buttonByText(document, '重新执行升级') !== null, 'durable retry ready')
  expectTrue(
    exactRetryConfirmation('sample-plugin', '0.6.0', '0.7.0') ===
      'RETRY sample-plugin@0.6.0->0.7.0',
    'retry confirmation is exact'
  )
  passed.push('durable retry readiness requires the exact retry confirmation tuple')

  configureRecoveryHttpMock('verification_required')
  buttonByText(document, '刷新状态')?.click()
  await waitFor(() => buttonByText(document, '检查恢复条件') !== null, 'recovery row restored')

  configureRecoveryHttpMock('blocked')
  const blockedDrawer = recoveryDrawer()
  if (!blockedDrawer) throw new Error('blocked recovery drawer missing')
  buttonByText(blockedDrawer, '刷新状态')?.click()
  await waitFor(
    () =>
      buttonByText(blockedDrawer, '重新执行升级') === null &&
      textOf(blockedDrawer).includes('当前状态不允许自动恢复'),
    'blocked refresh hides retry'
  )
  expectTrue(textOf(blockedDrawer).includes('当前状态不允许自动恢复'), 'blocked copy')
  expectTrue(buttonByText(blockedDrawer, '刷新状态') === null, 'blocked has no recovery buttons')
  passed.push('blocked hides every recovery button')

  configureRecoveryHttpMock('error')
  const errorHost = document.createElement('div')
  document.body.appendChild(errorHost)
  const errorApp = mountSandpackageInstallPage(errorHost)
  await waitFor(
    () => textOf(errorHost).includes('插件列表加载失败') || textOf(errorHost).includes('未能加载'),
    'list error visible'
  )
  expectTrue(buttonByText(errorHost, '重新执行升级') === null, 'list error hides retry')
  expectTrue(buttonByText(errorHost, '检查插件包') === null, 'list error closes writes')
  passed.push('list failure is observable and closes recovery writes')
  errorApp.unmount()
  errorHost.remove()

  retryApp.unmount()
  retryHost.remove()

  configureRecoveryHttpMock('cleanup_large')
  const cleanupHost = document.createElement('div')
  document.body.appendChild(cleanupHost)
  const cleanupApp = mountSandpackageInstallPage(cleanupHost)
  await waitFor(() => controlByText(cleanupHost, '清理残留') !== null, 'cleanup entry rendered')
  controlByText(cleanupHost, '清理残留')?.click()
  await waitFor(
    () =>
      textOf(cleanupDialog()).includes('现存业务表（86）') &&
      textOf(cleanupDialog()).includes('现存菜单（176）'),
    'cleanup inspection rendered'
  )
  const tableDetails = cleanupDialog()?.querySelectorAll('details.cleanup-residue-details')[0]
  const menuDetails = cleanupDialog()?.querySelectorAll('details.cleanup-residue-details')[1]
  expectTrue(
    tableDetails instanceof HTMLDetailsElement &&
      menuDetails instanceof HTMLDetailsElement &&
      !tableDetails.open &&
      !menuDetails.open &&
      tableDetails.querySelector('.cleanup-residue-scroll') !== null &&
      menuDetails.querySelector('.cleanup-residue-scroll') !== null,
    'cleanup table and menu details stay collapsed with bounded result containers'
  )
  const packageDetails = cleanupDialog()?.querySelector('details.cleanup-package')
  const packageSummary = packageDetails?.querySelector('summary')
  if (!(packageDetails instanceof HTMLDetailsElement) || !(packageSummary instanceof HTMLElement)) {
    throw new Error('supplemental cleanup package disclosure missing')
  }
  expectTrue(!packageDetails.open, 'successful inspection keeps supplemental package collapsed')
  packageSummary.click()
  await waitFor(
    () =>
      packageDetails.open &&
      buttonByText(cleanupDialog() ?? document, '使用此版本检查清理范围')?.disabled === false,
    'supplemental cleanup package ready'
  )
  expectTrue(
    textOf(cleanupDialog()).includes('v0.7.3'),
    'latest same-app repository version is selected by default'
  )
  buttonByText(cleanupDialog() ?? document, '使用此版本检查清理范围')?.click()
  await waitFor(
    () => textOf(cleanupDialog()).includes('补充清理包 0.7.3'),
    'supplemental cleanup package refreshes inspection'
  )
  expectTrue(
    cleanupHttpCounts().packages === 1 && cleanupHttpCounts().inspections === 2,
    'supplemental package only registers scope and reinspects'
  )
  const cleanupConfirm = buttonByText(cleanupDialog() ?? document, '清理并允许重新安装')
  expectTrue(cleanupConfirm?.disabled === true, 'cleanup stays disabled before exact app input')
  const cleanupInput = document.querySelector('#cleanup-confirm-app')
  if (!(cleanupInput instanceof HTMLInputElement)) throw new Error('cleanup confirmation missing')
  setNativeInputValue(cleanupInput, 'wrong-plugin')
  await nextTick()
  expectTrue(cleanupConfirm?.disabled === true, 'wrong cleanup app keeps submission disabled')
  setNativeInputValue(cleanupInput, 'sample-plugin')
  await waitFor(() => cleanupConfirm?.disabled === false, 'exact cleanup app enables submission')
  cleanupConfirm?.click()
  cleanupConfirm?.click()
  await waitFor(
    () => textOf(cleanupDialog()).includes('插件残留已清理'),
    'cleanup success rendered'
  )
  const successCounts = cleanupHttpCounts()
  expectTrue(successCounts.submits === 1, 'double cleanup click submits once')
  expectTrue(successCounts.lists >= 2, 'cleanup success refreshes installed plugins')
  expectTrue(successCounts.repositories >= 2, 'cleanup success refreshes repository state')
  expectTrue(successCounts.reloads === 0, 'cleanup does not automatically reload backend')
  expectTrue(
    textOf(cleanupDialog()).includes('菜单缓存刷新失败') &&
      buttonByText(cleanupDialog() ?? document, '重载后端服务') !== null,
    'cleanup warning keeps an explicit backend reload action'
  )
  buttonByText(cleanupDialog() ?? document, '重载后端服务')?.click()
  await waitFor(
    () => buttonByText(cleanupDialog() ?? document, '重载后端服务') === null,
    'backend reload clears restart requirement'
  )
  expectTrue(
    cleanupHttpCounts().reloads === 1 &&
      textOf(cleanupDialog()).includes('清理已完成，可以重新安装'),
    'backend reload runs only after user action and exposes reinstall'
  )
  expectTrue(
    textOf(cleanupDialog()).includes('菜单缓存刷新失败'),
    'successful backend reload preserves the unresolved backend warning'
  )
  buttonByText(cleanupDialog() ?? document, '去插件仓库')?.click()
  await waitFor(
    () =>
      textOf(cleanupHost).includes('sample-plugin') &&
      textOf(cleanupHost).includes('未安装') &&
      buttonByText(cleanupHost, '直接安装')?.disabled === false,
    'cleaned plugin exposes repository reinstall entry'
  )
  passed.push('cleanup requires exact app, prevents duplicate submit, and refreshes both lists')
  cleanupApp.unmount()
  cleanupHost.remove()

  configureRecoveryHttpMock('cleanup_ready')
  configureCleanupSubmitFailureOnce()
  const continuationHost = document.createElement('div')
  document.body.appendChild(continuationHost)
  const continuationApp = mountSandpackageInstallPage(continuationHost)
  await waitFor(
    () => controlByText(continuationHost, '清理残留') !== null,
    'cleanup retry entry rendered'
  )
  controlByText(continuationHost, '清理残留')?.click()
  await waitFor(
    () => textOf(cleanupDialog()).includes('现存业务表（1）'),
    'cleanup retry inspection rendered'
  )
  const firstAttemptInput = document.querySelector('#cleanup-confirm-app')
  if (!(firstAttemptInput instanceof HTMLInputElement)) {
    throw new Error('cleanup retry confirmation missing')
  }
  setNativeInputValue(firstAttemptInput, 'sample-plugin')
  await waitFor(
    () => buttonByText(cleanupDialog() ?? document, '清理并允许重新安装')?.disabled === false,
    'cleanup retry enabled'
  )
  buttonByText(cleanupDialog() ?? document, '清理并允许重新安装')?.click()
  await waitFor(
    () => textOf(cleanupDialog()).includes('文件归档暂未完成'),
    'cleanup failure remains visible'
  )
  expectTrue(
    buttonByText(cleanupDialog() ?? document, '清理并允许重新安装') === null,
    'failed cleanup discards inspected fingerprint'
  )
  expectTrue(
    buttonByText(cleanupDialog() ?? document, '使用此版本检查清理范围') === null,
    'failed cleanup locks supplemental range until phase is reinspected'
  )
  buttonByText(cleanupDialog() ?? document, '重新检查')?.click()
  await waitFor(
    () =>
      textOf(cleanupDialog()).includes('数据库清理步骤已经完成') &&
      textOf(cleanupDialog()).includes('没有发现插件业务表残留') &&
      textOf(cleanupDialog()).includes('没有发现插件菜单残留'),
    'files-pending continuation rendered'
  )
  const continuationInput = document.querySelector('#cleanup-confirm-app')
  if (!(continuationInput instanceof HTMLInputElement)) {
    throw new Error('cleanup continuation confirmation missing')
  }
  setNativeInputValue(continuationInput, 'sample-plugin')
  await waitFor(
    () => buttonByText(cleanupDialog() ?? document, '继续完成清理')?.disabled === false,
    'cleanup continuation enabled'
  )
  buttonByText(cleanupDialog() ?? document, '继续完成清理')?.click()
  await waitFor(
    () => textOf(cleanupDialog()).includes('插件残留已清理'),
    'cleanup continuation succeeds'
  )
  expectTrue(cleanupHttpCounts().submits === 2, 'failure continuation submits only after recheck')
  passed.push('cleanup failure drops old fingerprint and resumes from files-pending inspection')
  continuationApp.unmount()
  continuationHost.remove()

  return passed
}
