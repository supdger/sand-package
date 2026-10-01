type HostVersion = {
  core: string[]
  prerelease: boolean
}

const stableVersion = '(0|[1-9]\\d*)\\.(0|[1-9]\\d*)\\.(0|[1-9]\\d*)'
const hostPattern = new RegExp(
  `^${stableVersion}(?:-([0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*))?(?:\\+([0-9A-Za-z-]+(?:\\.[0-9A-Za-z-]+)*))?$`
)
const wildcardPattern = /^(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?\.x$/
const rangePattern = new RegExp(`^>=${stableVersion}(?:[ \t]+<=${stableVersion})?$`)

function compareCore(left: string[], right: string[]): number {
  for (let index = 0; index < 3; index++) {
    const a = left[index]
    const b = right[index]
    if (a.length !== b.length) return a.length < b.length ? -1 : 1
    if (a !== b) return a < b ? -1 : 1
  }
  return 0
}

function compareStable(host: HostVersion, bound: string[]): number {
  const result = compareCore(host.core, bound)
  return result === 0 && host.prerelease ? -1 : result
}

export function checkVersionCompatibility(support: unknown, hostVersion: unknown): boolean {
  if (typeof support !== 'string' || typeof hostVersion !== 'string') return false
  if (/[\r\n]/.test(support)) return false
  const host = hostPattern.exec(hostVersion)
  if (!host || host[0] !== hostVersion) return false
  const prerelease = host[4]
  if (prerelease?.split('.').some((part) => /^\d+$/.test(part) && /^0\d/.test(part))) {
    return false
  }

  const version: HostVersion = {
    core: host.slice(1, 4),
    prerelease: Boolean(prerelease)
  }
  let compatible = false
  for (const token of support.split('|')) {
    const branch = token.replace(/^[ \t]+|[ \t]+$/g, '')
    const wildcard = wildcardPattern.exec(branch)
    if (wildcard && wildcard[0] === branch) {
      compatible ||=
        wildcard[1] === version.core[0] &&
        (wildcard[2] === undefined || wildcard[2] === version.core[1])
      continue
    }
    const range = rangePattern.exec(branch)
    if (!range || range[0] !== branch) return false
    const minimum = range.slice(1, 4)
    const maximum = range[4] === undefined ? null : range.slice(4, 7)
    if (maximum && compareCore(minimum, maximum) > 0) return false
    compatible ||=
      compareStable(version, minimum) >= 0 &&
      (maximum === null || compareStable(version, maximum) <= 0)
  }
  return compatible
}
