import { runFailedUpgradeRecoveryBehaviorHarness } from './failed-upgrade-recovery.behavior'

const report = document.getElementById('behavior-report')

if (report) {
  void runFailedUpgradeRecoveryBehaviorHarness()
    .then((passed) => {
      report.dataset.status = 'passed'
      report.dataset.behaviorCount = String(passed.length)
      report.textContent = `PASSED ${passed.length}: ${passed.join(' | ')}`
    })
    .catch((error: unknown) => {
      report.dataset.status = 'failed'
      report.textContent =
        error instanceof Error ? (error.stack ?? error.message) : 'unknown behavior failure'
    })
}
