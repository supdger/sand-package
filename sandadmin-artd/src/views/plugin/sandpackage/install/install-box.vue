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
    <div class="upload-dialog">
      <ElAlert
        type="warning"
        :closable="false"
        title="只装你信得过的插件包。插件能改系统文件、改数据库、装新的依赖，来路不明的包可能把系统弄坏。"
      />

      <div v-if="uploaded && appInfo.app" class="upload-result">
        <ElAlert type="success" :closable="false" title="上传完成" />
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="应用标识">{{ appInfo.app }}</ElDescriptionsItem>
          <ElDescriptionsItem label="应用名称">{{ appInfo.title }}</ElDescriptionsItem>
          <ElDescriptionsItem label="应用描述">{{ appInfo.about }}</ElDescriptionsItem>
          <ElDescriptionsItem label="作者">{{ appInfo.author }}</ElDescriptionsItem>
          <ElDescriptionsItem label="版本">{{ appInfo.version }}</ElDescriptionsItem>
        </ElDescriptions>
      </div>

      <template v-else>
        <ElUpload
          drag
          :auto-upload="false"
          :show-file-list="false"
          :disabled="disabled || loading"
          accept=".zip"
          class="upload-drop"
          :on-change="onFileChange"
        >
          <div class="upload-drop-inner">
            <ArtSvgIcon icon="ri:upload-cloud-line" class="upload-icon" />
            <div class="upload-hint-line">
              将插件包文件拖到此处，或
              <span class="upload-pick">点击选择</span>
            </div>
            <div class="upload-limit">{{ uploadHint }}</div>
          </div>
        </ElUpload>

        <p v-if="selectedName" class="selected-file">已选择 {{ selectedName }}</p>
        <ElProgress v-if="loading" :percentage="uploadPercent" :stroke-width="8" />
        <p v-if="loading" class="upload-status">正在上传</p>
        <ElAlert v-if="oversizeText" type="warning" :closable="false" :title="oversizeText" />
        <ElAlert v-if="uploadError" type="error" :closable="false" :title="uploadError" />

        <div v-if="selectedName" class="upload-actions">
          <ElButton type="primary" :loading="loading" :disabled="!selectedFile" @click="startUpload">
            开始上传
          </ElButton>
          <ElButton :disabled="loading" @click="clearSelection">取消</ElButton>
        </div>
      </template>
    </div>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ref, reactive, computed } from 'vue'
  import { ElMessage } from 'element-plus'
  import type { UploadFile } from 'element-plus'
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
  const uploaded = ref(false)
  const uploadPercent = ref(0)
  const selectedName = ref('')
  const selectedFile = ref<File | null>(null)
  const oversizeText = ref('')
  const uploadError = ref('')

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
      return packageZipOversizeMessage(uploadSize.value, file.size)
    }
    return undefined
  }

  const clearUploadResult = (): void => {
    ElMessage.closeAll()
    uploaded.value = false
    uploadError.value = ''
    oversizeText.value = ''
    uploadPercent.value = 0
  }

  const onFileChange = (upload: UploadFile): void => {
    if (props.disabled || loading.value) return
    const file = upload.raw
    if (!file) return
    clearUploadResult()
    selectedName.value = file.name
    const message = uploadValidationMessage(file)
    selectedFile.value = message ? null : file
    if (message) oversizeText.value = message
  }

  const clearSelection = (): void => {
    if (loading.value) return
    selectedFile.value = null
    selectedName.value = ''
    clearUploadResult()
  }

  const startUpload = async (): Promise<void> => {
    const file = selectedFile.value
    if (!file || props.disabled || loading.value || !props.canStartWrite()) return
    const message = uploadValidationMessage(file)
    if (message) {
      oversizeText.value = message
      return
    }
    clearUploadResult()
    loading.value = true
    uploadPercent.value = 35
    emit('busy-change', true)
    try {
      const dataForm = new FormData()
      dataForm.append('file', file)
      const res = await sandpackageApi.uploadApp(dataForm)
      if (!res) {
        uploadError.value = '上传没有完成，请再试一次'
        return
      }
      uploadPercent.value = 100
      Object.assign(appInfo, res)
      uploaded.value = true
      ElMessage.closeAll()
      ElMessage.success('上传成功')
      await props.afterUpload()
      emit('success')
    } catch (error: unknown) {
      uploadError.value = error instanceof Error && error.message !== '' ? error.message : '上传没有完成，请再试一次'
    } finally {
      loading.value = false
      emit('busy-change', false)
    }
  }

  const open = (): void => {
    if (props.disabled || loading.value) return
    visible.value = true
    clearSelection()
    Object.assign(appInfo, initialApp)
    void refreshUploadLimit()
  }

  const beforeClose = (done: () => void): void => {
    if (!loading.value) done()
  }

  defineExpose({ open })
</script>

<style scoped>
  .upload-dialog {
    display: grid;
    gap: 16px;
  }

  .upload-drop {
    width: 100%;
  }

  .upload-drop-inner {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 28px 0;
  }

  .upload-icon {
    margin-bottom: 8px;
    font-size: 36px;
    color: var(--el-text-color-secondary);
  }

  .upload-hint-line,
  .upload-limit,
  .selected-file,
  .upload-status {
    color: var(--el-text-color-secondary);
  }

  .upload-pick {
    margin-left: 8px;
    color: var(--el-color-primary);
  }

  .upload-limit,
  .selected-file,
  .upload-status {
    margin: 8px 0 0;
    font-size: 13px;
  }

  .upload-actions {
    display: flex;
    gap: 12px;
  }

  .upload-result {
    display: grid;
    gap: 12px;
  }
</style>
