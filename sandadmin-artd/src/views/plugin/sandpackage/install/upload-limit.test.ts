import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { describeOversize, formatUploadLimit } from './upload-limit.ts'

describe('formatUploadLimit', () => {
  it('16MiB 只显示最大 16 MB，不出现原始字节数', () => {
    const label = formatUploadLimit(16 * 1024 * 1024)
    assert.equal(label, '最大 16 MB')
    assert.equal(label.includes('16777216'), false)
    const warning = describeOversize(16 * 1024 * 1024)
    assert.equal(warning.includes('16777216'), false)
    assert.match(warning, /最大 16 MB/)
  })
})
