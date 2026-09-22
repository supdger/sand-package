<template>
  <div>
    <!-- 终端执行面板 -->
    <ElDialog v-model="visible" title="终端执行面板" width="960">
      <div>
        <ElEmpty v-if="terminal.taskList.length === 0" description="暂无任务" />
        <div v-else>
          <ElTimeline>
            <ElTimelineItem
              v-for="(item, idx) in terminal.taskList"
              :key="idx"
              :timestamp="item.createTime"
              placement="top"
            >
              <ElCollapse :model-value="terminal.taskList.map((_, i) => i)">
                <ElCollapseItem :name="idx">
                  <template #title>
                    <div class="flex items-center gap-3">
                      <span class="font-bold text-base">{{ item.command }}</span>
                      <ElTag :type="getTagType(item.status)" size="small">
                        {{ getTagText(item.status) }}
                      </ElTag>
                    </div>
                  </template>
                  <template #icon>
                    <div class="flex gap-1">
                      <ElButton
                        type="warning"
                        size="small"
                        circle
                        @click.stop="terminal.retryTask(idx)"
                      >
                        <ArtSvgIcon icon="ri:refresh-line" />
                      </ElButton>
                      <ElButton
                        type="danger"
                        size="small"
                        circle
                        @click.stop="terminal.delTask(idx)"
                      >
                        <ArtSvgIcon icon="ri:delete-bin-line" />
                      </ElButton>
                    </div>
                  </template>
                  <div
                    v-if="
                      item.status === 2 ||
                      item.status === 3 ||
                      (item.status > 3 && item.showMessage)
                    "
                    class="exec-message"
                  >
                    <pre v-for="(msg, index) in item.message" :key="index" v-text="msg"></pre>
                  </div>
                </ElCollapseItem>
              </ElCollapse>
            </ElTimelineItem>
          </ElTimeline>
        </div>

        <div class="flex justify-center flex-wrap gap-2">
          <span class="text-sm text-gray-500">只能从插件的待安装依赖操作启动任务。</span>
          <ElButton type="danger" @click="terminal.cleanTaskList()">
            <template #icon>
              <ArtSvgIcon icon="ri:delete-bin-line" />
            </template>
            清理任务
          </ElButton>
        </div>
      </div>
    </ElDialog>
  </div>
</template>

<script setup lang="ts">
  import { ref } from 'vue'
  import { ElMessage } from 'element-plus'
  import { useTerminalStore, TaskStatus } from '../store/terminal'

  const emit = defineEmits<{
    (e: 'success'): void
  }>()

  const terminal = useTerminalStore()
  const visible = ref(false)
  const reportInstallResult = (status: TaskStatus, label: string) => {
    if (status === TaskStatus.SUCCESS) {
      ElMessage.success(`${label}已完成`)
    } else {
      ElMessage.warning(`${label}结果待确认，已刷新插件状态`)
    }
    emit('success')
  }

  const frontInstall = (extend: string) => {
    if (!extend) return
    terminal.addNodeTask('web-install', extend, (status) =>
      reportInstallResult(status, '前端依赖更新')
    )
  }

  const backendInstall = (extend: string) => {
    if (!extend) return
    terminal.addTask('composer.update', extend, (status) =>
      reportInstallResult(status, '后端依赖更新')
    )
  }

  const getTagType = (
    status: TaskStatus
  ): 'success' | 'warning' | 'info' | 'danger' | 'primary' => {
    switch (status) {
      case TaskStatus.WAITING:
        return 'info'
      case TaskStatus.CONNECTING:
        return 'primary'
      case TaskStatus.RUNNING:
        return 'warning'
      case TaskStatus.SUCCESS:
        return 'success'
      case TaskStatus.FAILED:
        return 'danger'
      default:
        return 'info'
    }
  }

  const getTagText = (status: TaskStatus) => {
    switch (status) {
      case TaskStatus.WAITING:
        return '等待执行'
      case TaskStatus.CONNECTING:
        return '连接中'
      case TaskStatus.RUNNING:
        return '执行中'
      case TaskStatus.SUCCESS:
        return '执行成功'
      case TaskStatus.FAILED:
        return '执行失败'
      default:
        return '未知'
    }
  }

  const open = () => {
    visible.value = true
  }

  const close = () => {
    visible.value = false
  }

  defineExpose({ open, close, frontInstall, backendInstall })
</script>

<style lang="scss" scoped>
  .exec-message {
    font-size: 12px;
    line-height: 1.5em;
    min-height: 30px;
    max-height: 200px;
    overflow: auto;
    background-color: #000;
    color: #c0c0c0;
    padding: 8px;
    border-radius: 4px;

    &::-webkit-scrollbar {
      width: 5px;
      height: 5px;
    }

    &::-webkit-scrollbar-thumb {
      background: #c8c9cc;
      border-radius: 4px;
    }
  }
</style>
