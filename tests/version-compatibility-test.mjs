import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { resolve } from 'node:path'
import { pathToFileURL } from 'node:url'

// Run with an existing frontend dependency directory; no package installation is needed.
const dependencyRoot = process.argv[2]
if (!dependencyRoot) {
  console.error('Usage: node tests/version-compatibility-test.mjs <frontend-node_modules>')
  process.exit(1)
}

try {
  const require = createRequire(pathToFileURL(resolve(dependencyRoot, 'package.json')))
  const ts = require('typescript')
  const source = new URL('../sandadmin-artd/src/views/plugin/sandpackage/install/version-compatibility.ts', import.meta.url)
  const fixtures = new URL('../server/tests/SandPackage/fixtures/host-version-compatibility.json', import.meta.url)
  console.log('Loading the actual frontend compatibility helper and shared fixtures')
  const compiled = ts.transpileModule(readFileSync(source, 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2021, module: ts.ModuleKind.ES2022 }
  })
  const { checkVersionCompatibility } = await import(
    `data:text/javascript;base64,${Buffer.from(compiled.outputText).toString('base64')}`
  )
  const cases = JSON.parse(readFileSync(fixtures, 'utf8'))
  for (const test of cases) {
    assert.equal(checkVersionCompatibility(test.support, test.host), test.expected, test.name)
  }
  console.log(`Frontend compatibility: ${cases.length}/${cases.length} shared fixtures passed`)
} catch (error) {
  console.error('Frontend compatibility regression failed:', error)
  process.exitCode = 1
}
