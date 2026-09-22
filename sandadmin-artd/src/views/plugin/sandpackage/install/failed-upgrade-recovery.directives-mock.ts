import type { App } from 'vue'

/** 真实页面挂载时只替换全局指令注册，避免拉起路由/权限运行时。 */
export function setupGlobDirectives(app: App): void {
  app.directive('ripple', {})
  app.directive('permission', {})
}
