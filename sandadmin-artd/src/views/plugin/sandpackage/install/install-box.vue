<template>
  <ElDialog
    v-model="visible"
    title="上传插件包"
    width="800"
    :close-on-click-modal="false"
    :close-on-press-escape="!loading"
    :show-close="!loading"
    :before-close="beforeClose"
  >
    <div class="flex flex-col items-center mb-6">
      <div class="w-[400px]">
        <div class="text-lg text-red-500 font-bold mb-2">
          请您务必确认插件包来自官方渠道或经由官方认证的插件作者，否则系统可能被破坏，因为：
        </div>
        <div class="text-red-500">1. 插件可以修改和新增系统文件</div>
        <div class="text-red-500">2. 插件可以执行数据库命令和代码</div>
        <div class="text-red-500">3. 插件可以安装新的前后端依赖</div>
      </div>

      <!-- 已上传的应用信息 -->
      <div v-if="appInfo && appInfo.app" class="mt-10 w-[600px]">
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="应用标识">{{ appInfo?.app }}</ElDescriptionsItem>
          <ElDescriptionsItem label="应用名称">{{ appInfo?.title }}</ElDescriptionsItem>
          <ElDescriptionsItem label="应用描述">{{ appInfo?.about }}</ElDescriptionsItem>
          <ElDescriptionsItem label="作者">{{ appInfo?.author }}</ElDescriptionsItem>
          <ElDescriptionsItem label="版本">{{ appInfo?.version }}</ElDescriptionsItem>
        </ElDescriptions>
      </div>

      <!-- 上传区域 -->
      <div v-else class="mt-10 w-[600px]">
        <ElUpload
          drag
          :http-request="uploadFileHandler"
          :before-upload="beforeUpload"
          :show-file-list="false"
          :disabled="disabled || loading"
          accept=".zip"
          class="w-full"
        >
          <div class="flex flex-col items-center justify-center py-8">
            <ArtSvgIcon icon="ri:upload-cloud-line" class="text-4xl text-gray-400 mb-2" />
            <div class="text-gray-500">
              将插件包文件拖到此处，或
              <span class="text-primary ml-2">点击上传</span>
            </div>
            <div class="mt-2 text-sm text-gray-500">{{ uploadHint }}</div>
            <div v-if="selectedName" class="mt-2 text-sm">已选择 {{ selectedName }}</div>
          </div>
        </ElUpload>
      </div>
    </div>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ref, reactive, computed } from 'vue'
  import { ElMessage } from 'element-plus'
  import type { UploadProps, UploadRequestOptions } from 'element-plus'
  import sandpackageApi, { type AppInfo } from '../api/index'
  import {
    packageZipMaxBytesValue,
    packageZipOversizeMessage,
    setPackageZipMaxBytes
  } from './failed-upgrade-recovery'
  import { formatUploadLimit } from './upload-limit'

  const props = withDefaults(
    defineProps<{
      disabled?: boolean
      canStartWrite?: () => boolean
      afterUpload?: () => Promise<void>
    }>(),
    {
      disabled: false,
      canStartWrite: () => true,
      afterUpload: async () => undefined
    }
  )

  const emit = defineEmits<{
    (e: 'success'): void
    (e: 'busy-change', busy: boolean): void
  }>()

  const visible = ref(false)
  const loading = ref(false)
  const selectedName = ref('')

  /** 上限由后端 effectiveLimit 下发，取不到时沿用插件包兜底，不在前端定义业务值。 */
  const uploadSize = ref(packageZipMaxBytesValue())

  const uploadHint = computed(() => `仅支持 ZIP 格式，${formatUploadLimit(uploadSize.value)}`)

  const refreshUploadLimit = async (): Promise<void> => {
    try {
      const limits = await sandpackageApi.getPackageLimits({ showErrorMessage: false })
      uploadSize.value = setPackageZipMaxBytes(limits?.effective_limit)
      if (limits?.self_check?.state === 'fail' || limits?.self_check?.state === 'warn') {
        ElMessage.warning(limits.self_check.notes)
      }
    } catch {
      // 读取不到后端口径时保留当前兜底上限，上传仍由后端做最终判定。
      uploadSize.value = packageZipMaxBytesValue()
    }
  }

  const initialApp: AppInfo = {
    app: '',
    title: '',
    about: '',
    author: '',
    version: '',
    state: 0
  }

  const appInfo = reactive<AppInfo>({ ...initialApp })

  const uploadValidationMessage = (file: File): string | undefined => {
    if (!file.name.toLowerCase().endsWith('.zip')) {
      return '仅支持 ZIP 格式的插件包'
    }
    if (file.size > uploadSize.value) {
      return packageZipOversizeMessage(uploadSize.value)
    }
    return undefined
  }

  const beforeUpload: UploadProps['beforeUpload'] = (file) => {
    if (props.disabled || loading.value) return false
    const message = uploadValidationMessage(file)
    if (!message) return true

    ElMessage.warning(message)
    return false
  }

  const uploadFileHandler = async (options: UploadRequestOptions): Promise<unknown> => {
    if (props.disabled || loading.value || !props.canStartWrite()) {
      throw new Error('当前有插件操作正在进行，请稍后重试')
    }
    const file = options.file
    const message = uploadValidationMessage(file)
    if (message) {
      throw new Error(message)
    }

    selectedName.value = file.name
    loading.value = true
    emit('busy-change', true)
    try {
      const dataForm = new FormData()
      dataForm.append('file', file)

      const res = await sandpackageApi.uploadApp(dataForm)
      if (res) {
        Object.assign(appInfo, res)
        visible.value = false
        ElMessage.success('上传成功')
        await props.afterUpload()
        emit('success')
      }
      return res
    } finally {
      loading.value = false
      emit('busy-change', false)
    }
  }

  const open = () => {
    if (props.disabled || loading.value) return
    visible.value = true
    selectedName.value = ''
    Object.assign(appInfo, initialApp)
    void refreshUploadLimit()
  }

  const beforeClose = (done: () => void): void => {
    if (!loading.value) done()
  }

  defineExpose({ open })
</script>
