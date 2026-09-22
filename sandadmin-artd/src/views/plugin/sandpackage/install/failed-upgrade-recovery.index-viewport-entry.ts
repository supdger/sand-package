/**
 * 视口入口：挂载实际 index.vue。mode 只选择 HTTP 替身的列表夹具。
 */
import {
  configureCleanupSubmitFailureOnce,
  configureRecoveryHttpMock,
  type RecoveryListFixture
} from './failed-upgrade-recovery.http-mock'
import { mountSandpackageInstallPage } from './failed-upgrade-recovery.index-mount'

const root = document.getElementById('app')
if (root) {
  const raw = new URLSearchParams(window.location.search).get('mode')
  const fixture: RecoveryListFixture =
    raw === 'blocked'
      ? 'blocked'
      : raw === 'retry-ready'
        ? 'retry_ready'
        : raw === 'cleanup-ready'
          ? 'cleanup_large'
          : raw === 'cleanup-failure'
            ? 'cleanup_ready'
            : raw === 'cleanup-pending'
              ? 'cleanup_pending'
              : 'verification_required'
  configureRecoveryHttpMock(fixture)
  if (raw === 'cleanup-failure') configureCleanupSubmitFailureOnce()
  mountSandpackageInstallPage(root)
}
