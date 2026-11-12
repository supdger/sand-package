import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { formatSupportText } from './support-label.ts'

describe('formatSupportText', () => {
  it('下限写成需要某版本及以上', () => {
    assert.equal(formatSupportText('>=0.2.0'), '需要 0.2.0 及以上')
  })

  it('上下限写成需要某版本至某版本', () => {
    assert.equal(formatSupportText('>=0.2.0 <=0.3.0'), '需要 0.2.0 至 0.3.0')
  })
})
