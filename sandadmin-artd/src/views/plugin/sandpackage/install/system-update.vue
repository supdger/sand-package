<template>
  <section class="system-update" aria-label="系统更新">
    <div class="update-toolbar">
      <div>
        <h3>系统更新</h3>
        <p>更新 SandAdmin 核心和插件管理器。先检查兼容性与本地修改，再确认升级。</p>
      </div>
      <ElButton :loading="operation === 'status'" :disabled="busy" @click="loadStatus">检查更新</ElButton>
    </div>
    <ElAlert v-if="error" type="error" :closable="false" title="操作未完成">
      <p>{{ error }}</p>
      <ElButton :disabled="operation !== ''" @click="reconnect">重新连接并查询结果</ElButton>
    </ElAlert>
    <ElAlert v-if="!status && !error" type="info" :closable="false" title="正在读取版本和升级条件" />
    <template v-if="status">
      <ElAlert v-if="!status.capabilities.supported" type="warning" :closable="false" title="当前环境尚不能执行系统更新">
        {{ status.capabilities.reason }}
      </ElAlert>
      <div class="version-list" aria-label="系统组件版本">
        <div v-for="item in status.installed" :key="item.package" class="version-row">
          <div><strong>{{ item.name }}</strong><p>已安装版本：{{ item.version }}</p></div>
          <label class="version-select">
            <span>目标版本</span>
            <ElSelect v-model="selected[item.package]" :disabled="busy || externalBusy" placeholder="暂无可用更新" clearable @change="invalidateInspection">
              <ElOption v-for="release in releasesFor(item.package)" :key="release.version" :label="release.version" :value="release.version" />
            </ElSelect>
          </label>
        </div>
      </div>
      <div v-for="release in selectedReleases" :key="release.package" class="release-notes">
        <strong>{{ release.name }} {{ release.version }} · 更新说明</strong>
        <p>{{ release.notes || '此版本未提供更新说明。' }}</p>
      </div>
      <ul v-if="checks.length" class="check-list" aria-label="升级检查结果">
        <li v-for="check in checks" :key="check.code">
          <div class="check-heading"><strong>{{ check.label }}</strong><ElTag :type="check.state === 'ok' ? 'success' : 'danger'">{{ check.state === 'ok' ? '通过' : '未通过' }}</ElTag></div>
          <p>{{ check.message }}</p>
        </li>
      </ul>
      <ElAlert v-if="externalBusy" type="info" :closable="false" title="请先完成插件安装、恢复或依赖处理，再更新系统。" />
      <ElSpace wrap>
        <ElButton type="primary" :loading="operation === 'inspect'" :disabled="busy || updateBlocked || externalBusy || !targets.length || !status.capabilities.supported" @click="inspect">检查所选升级</ElButton>
        <ElButton v-if="inspection?.can_start" type="warning" :disabled="busy || externalBusy" @click="confirmVisible = true">确认升级</ElButton>
        <span v-if="inspection && !inspection.can_start">请处理未通过的检查项后重新检查。</span>
      </ElSpace>
    </template>
    <section v-if="task" class="task-panel" aria-label="升级任务">
      <h3>最近升级任务 · {{ stateLabels[task.state] }}</h3>
      <p>任务 {{ task.id }} · 当前阶段：{{ task.stage }}</p>
      <p>刷新或重新打开页面后，仍可查询任务结果；连接中断不代表升级失败。</p>
      <ElAlert v-if="task.error && task.state !== 'recovered'" type="error" :closable="false" :title="task.error" />
      <ElAlert v-if="task.state === 'succeeded'" type="success" :closable="false" title="升级完成，健康检查已通过。请刷新页面加载新版本。" />
      <ElAlert v-if="task.state === 'recovered'" type="warning" :closable="false" title="已恢复升级前版本。请检查失败原因后重新检查更新。" />
      <ElButton v-if="recoveryEligible" type="warning" :disabled="busy || outcomeUnconfirmed || externalBusy" @click="confirmRecovery">恢复升级前版本</ElButton>
      <ol class="task-logs" aria-label="升级日志" aria-live="polite">
        <li v-for="(log, index) in task.logs" :key="`${index}-${log.time}`"><time>{{ formatTime(log.time) }}</time> {{ log.message }}</li>
      </ol>
    </section>
    <ElDialog v-model="confirmVisible" title="确认系统升级" width="min(560px, 94vw)" :close-on-click-modal="false">
      <p>将更新以下组件：</p>
      <ul><li v-for="target in inspection?.targets" :key="target.package">{{ packageName(target.package) }} → {{ target.version }}</li></ul>
      <p>系统将备份文件、安装版本并执行健康检查。更新期间后台可能短暂断开连接，请避免同时处理插件或修改部署文件。</p>
      <p>预检发现本地修改或不兼容时会阻止升级；本次检查有效期结束后需要重新检查。</p>
      <template #footer>
        <ElButton :disabled="operation === 'start'" @click="confirmVisible = false">取消</ElButton>
        <ElButton type="warning" :loading="operation === 'start'" :disabled="busy || externalBusy" @click="start">开始升级</ElButton>
      </template>
    </ElDialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessageBox } from 'element-plus'
import { systemUpdateApi, type SystemPackage, type UpdateInspection, type UpdateStatus, type UpdateTask, type UpdateTarget } from '../api/system-update'

const props = defineProps<{ externalBusy: boolean }>()
const emit = defineEmits<{ busy: [value: boolean] }>()
const status = ref<UpdateStatus | null>(null)
const inspection = ref<UpdateInspection | null>(null)
const selected = ref<Partial<Record<SystemPackage, string>>>({})
const task = ref<UpdateTask | null>(null)
const operation = ref('')
const error = ref('')
const confirmVisible = ref(false)
const outcomeUnconfirmed = ref(false)
let disposed = false
let timer: ReturnType<typeof setTimeout> | undefined
let requestGeneration = 0
const stateLabels: Record<UpdateTask['state'], string> = { queued: '等待执行', running: '正在升级', succeeded: '升级成功', failed: '升级失败', recovering: '正在恢复', recovered: '已恢复', recovery_required: '需要恢复' }
const taskRunning = computed(() => !!task.value && ['queued', 'running', 'recovering'].includes(task.value.state))
const busy = computed(() => operation.value !== '' || taskRunning.value)
const recoveryEligible = computed(() => !!task.value?.recovery_available && ['failed', 'recovery_required'].includes(task.value.state))
const updateBlocked = computed(() => outcomeUnconfirmed.value || task.value?.state === 'recovery_required')
const targets = computed<UpdateTarget[]>(() => (Object.entries(selected.value) as [SystemPackage, string][]).filter(([, version]) => !!version).map(([packageName, version]) => ({ package: packageName, version })))
const checks = computed(() => inspection.value?.checks ?? status.value?.checks ?? [])
const selectedReleases = computed(() => status.value?.releases.filter(release => selected.value[release.package] === release.version) ?? [])
watch(() => busy.value || updateBlocked.value, value => emit('busy', value), { immediate: true })
function releasesFor(packageName: string) { return status.value?.releases.filter(release => release.package === packageName) ?? [] }
function packageName(value: string) { return status.value?.installed.find(item => item.package === value)?.name ?? value }
function formatTime(value: number) { return new Date(value * 1000).toLocaleString() }
function invalidateInspection() { inspection.value = null; confirmVisible.value = false }
function message(value: unknown) { return value instanceof Error ? value.message : '请求未完成，请重新连接查询实际结果。' }
function schedulePoll() {
  clearTimeout(timer)
  if (!disposed && taskRunning.value) timer = setTimeout(() => { void pollTask() }, 2500)
}
async function pollTask() {
  if (!task.value || disposed) return
  const generation = requestGeneration
  try {
    const result = await systemUpdateApi.task(task.value.id)
    if (disposed || generation !== requestGeneration) return
    task.value = result
    error.value = ''
    if (!taskRunning.value) void loadStatus()
  } catch (cause) {
    if (!disposed && generation === requestGeneration) error.value = `暂时无法读取升级进度：${message(cause)}。正在尝试重新连接。`
  } finally { if (!disposed && generation === requestGeneration) schedulePoll() }
}
async function loadStatus() {
  if (operation.value || disposed) return
  const generation = ++requestGeneration
  operation.value = 'status'
  error.value = ''
  try {
    const result = await systemUpdateApi.status()
    if (disposed || generation !== requestGeneration) return
    status.value = result
    outcomeUnconfirmed.value = false
    task.value = result.active_task
    invalidateInspection()
    const choices: Partial<Record<SystemPackage, string>> = {}
    for (const installed of result.installed) {
      const available = result.releases.filter(release => release.package === installed.package && release.version !== installed.version)
      const previous = selected.value[installed.package]
      const choice = available.find(release => release.version === previous) ?? available[0]
      if (choice) choices[installed.package] = choice.version
    }
    selected.value = choices
  } catch (cause) { if (!disposed && generation === requestGeneration) { status.value = null; invalidateInspection(); error.value = message(cause) } }
  finally { if (!disposed && generation === requestGeneration) { operation.value = ''; schedulePoll() } }
}
async function reconnect() { if (taskRunning.value) await pollTask(); else await loadStatus() }
async function inspect() {
  if (busy.value || updateBlocked.value || props.externalBusy) return
  operation.value = 'inspect'; error.value = ''; invalidateInspection()
  try { const result = await systemUpdateApi.inspect(targets.value); if (!disposed) inspection.value = result }
  catch (cause) { if (!disposed) error.value = message(cause) }
  finally { if (!disposed) operation.value = '' }
}
async function start() {
  if (busy.value || props.externalBusy || !inspection.value?.can_start) return
  if (inspection.value.expires_at * 1000 <= Date.now()) { invalidateInspection(); error.value = '升级检查已过期，请重新检查所选升级。'; return }
  const confirmation = inspection.value.confirmation
  operation.value = 'start'; error.value = ''
  try { const result = await systemUpdateApi.start(confirmation); if (!disposed) task.value = result }
  catch (cause) { if (!disposed) { outcomeUnconfirmed.value = true; error.value = `提交结果未确认：${message(cause)}。请重新连接查询结果，不要重复提交。` } }
  finally { if (!disposed) { operation.value = ''; invalidateInspection(); schedulePoll() } }
}
async function confirmRecovery() {
  if (busy.value || props.externalBusy || !task.value || !recoveryEligible.value || outcomeUnconfirmed.value) return
  try { await ElMessageBox.confirm('恢复本次升级备份中的文件和版本。恢复后将重新检查服务状态，期间后台可能短暂断开。', '恢复升级前版本', { type: 'warning', confirmButtonText: '开始恢复', cancelButtonText: '取消' }) }
  catch { return }
  if (disposed || busy.value || props.externalBusy || !task.value || !recoveryEligible.value || outcomeUnconfirmed.value) return
  operation.value = 'recover'; error.value = ''
  try { const result = await systemUpdateApi.recover(task.value.id); if (!disposed) task.value = result }
  catch (cause) { if (!disposed) { outcomeUnconfirmed.value = true; error.value = `恢复结果未确认：${message(cause)}。请重新连接查询结果。` } }
  finally { if (!disposed) { operation.value = ''; schedulePoll() } }
}
onMounted(loadStatus)
onBeforeUnmount(() => { disposed = true; requestGeneration++; clearTimeout(timer); emit('busy', false) })
</script>

<style scoped>
.system-update { display: grid; grid-template-columns: minmax(0, 1fr); min-width: 0; max-width: 100%; box-sizing: border-box; gap: 20px; padding: 12px; overflow-wrap: anywhere; }
.system-update > * { min-width: 0; }
.version-list, .check-list { border: 1px solid var(--el-border-color); border-radius: 4px; }
.version-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 16px; }
.version-row + .version-row, .check-list li + li { border-top: 1px solid var(--el-border-color); }
.version-select { display: grid; gap: 8px; width: 220px; max-width: 100%; }
.check-list { list-style: none; padding: 0; margin: 0; }
.check-list li { padding: 16px; }
.check-heading { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.update-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px; }
h3 { margin: 0 0 8px; font-size: 18px; }
p { margin: 8px 0; line-height: 1.6; }
.release-notes p { white-space: pre-wrap; overflow-wrap: anywhere; }
.task-panel { border-top: 1px solid var(--el-border-color); padding-top: 20px; }
.task-logs { max-height: 320px; overflow: auto; padding: 12px 12px 12px 32px; background: var(--el-fill-color-light); overflow-wrap: anywhere; }
.task-logs li { margin: 8px 0; }
time { color: var(--el-text-color-secondary); margin-right: 8px; }
</style>
