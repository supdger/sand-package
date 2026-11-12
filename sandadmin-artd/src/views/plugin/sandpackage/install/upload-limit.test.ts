import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import { describeOversize, formatUploadLimit } from './upload-limit.ts'

describe('formatUploadLimit', () => {
  it('16MiB 只显示最大 16 MB，不出现原始字节数', () => {
    const label = formatUploadLimit(16 * 1024 * 1024)
    assert.equal(label, '最大 16 MB')
    assert.equal(label.includes('16777216'), false)
    const warning = describeOversize(16 * 1024 * 1024, 17.25 * 1024 * 1024)
    assert.equal(warning.includes('16777216'), false)
    assert.equal(warning.includes('前端校验'), false)
    assert.equal(warning, '插件包不能超过 16 MB，这个有 17.3 MB')
  })
})
