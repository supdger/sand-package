/**
 * 将真实目标页 index.vue 挂到宿主节点。仅在模块边界替换 HTTP；不手写恢复壳。
 */
import { createApp, defineComponent, h, type App } from 'vue'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import ElementPlus from 'element-plus'
import zhCn from 'element-plus/es/locale/lang/zh-cn'
import { setupGlobDirectives } from '@/directives'
import ArtTable from '@/components/core/tables/art-table/index.vue'
import InstallPage from './index.vue'
import 'element-plus/dist/index.css'
import '@styles/core/tailwind.css'
import '@styles/index.scss'

/** 仅补全局图标；恢复流程与表格仍由真实组件渲染。 */
const ArtSvgIconStub = defineComponent({
  name: 'ArtSvgIcon',
  props: {
    icon: { type: String, default: '' }
  },
  setup(props) {
    return () => h('span', { class: 'art-svg-icon', 'data-icon': props.icon })
  }
})

export function mountSandpackageInstallPage(host: HTMLElement): App {
  const app = createApp(InstallPage)
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/', component: InstallPage }]
  })
  app.use(createPinia())
  app.use(router)
  app.use(ElementPlus, { locale: zhCn })
  app.component('ArtSvgIcon', ArtSvgIconStub)
  app.component('ArtTable', ArtTable)
  setupGlobDirectives(app)
  app.mount(host)
  return app
}
