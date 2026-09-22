import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const installRoot = path.dirname(fileURLToPath(import.meta.url))
const stagedSandpackageRoot = path.resolve(installRoot, '..')
const frontendRoot = path.resolve(installRoot, '../../../../..')
const sourceRoot = path.join(frontendRoot, 'src')
const sourceInstallRoot = `${sourceRoot}/views/plugin/sandpackage/install`
const sourceApiRoot = `${sourceRoot}/views/plugin/sandpackage/api`
const mockHttp = path.join(installRoot, 'failed-upgrade-recovery.http-mock.ts')
const mockDirectives = path.join(installRoot, 'failed-upgrade-recovery.directives-mock.ts')
const sourceNodeModules = path.join(frontendRoot, 'node_modules')

function stageComponentResolver() {
  return {
    name: 'sandpackage-failed-upgrade-stage-resolver',
    enforce: 'pre' as const,
    resolveId(id: string, importer?: string) {
      if (id === '@/utils/http') return mockHttp
      if (id === '@/directives') return mockDirectives
      if (!importer?.startsWith(installRoot)) return null
      if (id === '../api/index') return path.join(sourceApiRoot, 'index.ts')
      if (id === './install-box.vue') return path.join(sourceInstallRoot, 'install-box.vue')
      if (id === './terminal.vue') return path.join(sourceInstallRoot, 'terminal.vue')
      return null
    }
  }
}

export default defineConfig({
  root: installRoot,
  publicDir: false,
  define: {
    __APP_VERSION__: JSON.stringify('failed-upgrade-recovery-harness')
  },
  plugins: [stageComponentResolver(), vue()],
  resolve: {
    alias: [
      { find: /^@\/utils\/http$/, replacement: mockHttp },
      { find: /^@\/directives$/, replacement: mockDirectives },
      { find: '@', replacement: sourceRoot },
      { find: '@styles', replacement: `${sourceRoot}/assets/styles` },
      { find: '@views', replacement: `${sourceRoot}/views` },
      { find: '@imgs', replacement: `${sourceRoot}/assets/images` },
      { find: '@icons', replacement: `${sourceRoot}/assets/icons` },
      { find: '@utils', replacement: `${sourceRoot}/utils` },
      { find: '@stores', replacement: `${sourceRoot}/store` },
      {
        find: 'vue',
        replacement: `${sourceNodeModules}/vue/dist/vue.runtime.esm-bundler.js`
      },
      {
        find: 'pinia',
        replacement: `${sourceNodeModules}/pinia/dist/pinia.mjs`
      },
      {
        find: 'vue-router',
        replacement: `${sourceNodeModules}/vue-router/dist/vue-router.mjs`
      },
      {
        find: 'element-plus',
        replacement: `${sourceNodeModules}/element-plus`
      }
    ]
  },
  server: {
    host: '127.0.0.1',
    fs: {
      allow: [installRoot, stagedSandpackageRoot, sourceRoot, sourceNodeModules]
    }
  },
  build: {
    outDir: path.join(frontendRoot, 'dist', 'failed-upgrade-recovery'),
    emptyOutDir: true,
    rollupOptions: {
      input: {
        behavior: path.join(installRoot, 'failed-upgrade-recovery.behavior.html'),
        viewport: path.join(installRoot, 'failed-upgrade-recovery.viewport.html')
      }
    }
  }
})
