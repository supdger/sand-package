<template>
  <div class="sandpackage-page">
    <ElCard class="sandpackage-page-card" shadow="never">
      <!-- Tab切换 -->
      <ElTabs v-model="activeTab" type="border-card">
        <!-- 插件管理 Tab -->
        <ElTabPane label="插件管理" name="local">
          <div class="local-toolbar">
            <ElSpace wrap>
              <ElButton
                v-ripple
                :loading="loading"
                :disabled="pluginOperationBusy"
                @click="getList()"
              >
                <template #icon>
                  <ArtSvgIcon icon="ri:refresh-line" />
                </template>
                刷新
              </ElButton>
              <ElButton
                v-if="!hideGlobalPluginWrites"
                v-ripple
                :disabled="pluginOperationBusy"
                @click="handleUpload"
              >
                <template #icon>
                  <ArtSvgIcon icon="ri:upload-line" />
                </template>
                上传插件包
              </ElButton>
              <ElButton
                v-if="!hideGlobalPluginWrites"
                v-ripple
                :disabled="pluginOperationBusy"
                @click="handleTerminal"
              >
                <template #icon>
                  <ArtSvgIcon icon="ri:terminal-box-line" />
                </template>
                依赖安装终端
              </ElButton>
            </ElSpace>
          </div>

          <ElAlert
            v-if="listError"
            class="mb-3"
            type="error"
            :closable="false"
            title="插件列表未能加载"
          >
            <div class="failed-upgrade-error-row">
              <span>{{ listError }}</span>
              <ElButton size="small" @click="getList()">重新加载</ElButton>
            </div>
          </ElAlert>

          <ElCollapse class="environment-info">
            <ElCollapseItem title="环境信息" name="environment">
              <ElDescriptions :column="2" border>
                <ElDescriptionsItem label="SandAdmin">
                  {{ version?.sandadmin_version?.describe || '未知' }}
                  <ElTag
                    class="ml-2"
                    size="small"
                    :type="version?.sandadmin_version?.notes === '正常' ? 'success' : 'danger'"
                  >
                    {{ version?.sandadmin_version?.notes || '状态未知' }}
                  </ElTag>
                </ElDescriptionsItem>
                <ElDescriptionsItem label="插件安装器">
                  {{ version?.sandpackage_version?.describe || '未知' }}
                  <ElTag
                    class="ml-2"
                    size="small"
                    :type="version?.sandpackage_version?.notes === '正常' ? 'success' : 'danger'"
                  >
                    {{ version?.sandpackage_version?.notes || '状态未知' }}
                  </ElTag>
                </ElDescriptionsItem>
              </ElDescriptions>
            </ElCollapseItem>
          </ElCollapse>

          <ArtTable
            :loading="loading"
            :data="installList"
            :columns="columns"
            :show-table-header="false"
          >
            <!-- 插件标识列 -->
            <template #app="{ row }">
              <ElLink :href="row.website" target="_blank" type="primary">{{ row.app }}</ElLink>
            </template>

            <!-- 状态列 -->
            <template #state="{ row }">
              <ElTooltip v-if="isLegacyFailedUpgradeRecovery(row)" placement="top">
                <template #content>
                  <div>{{ recoveryReason(row) }}</div>
                </template>
                <ElTag type="danger">{{ stateText(row) }}</ElTag>
              </ElTooltip>
              <ElTooltip
                v-else-if="row.stage_label || row.last_error || row.recovery_reason"
                placement="top"
              >
                <template #content>
                  <div>{{ localDetailReason(row) }}</div>
                </template>
                <ElTag :type="stateTagType(row)">{{ stateText(row) }}</ElTag>
              </ElTooltip>
              <ElTag v-else :type="stateTagType(row)">{{ stateText(row) }}</ElTag>
            </template>

            <!-- 前端依赖列 -->
            <template #npm="{ row }">
              <ElTag v-if="isLegacyFailedUpgradeRecovery(row)" type="info">-</ElTag>
              <ElLink
                v-else-if="row.npm_dependent_wait_install === 1"
                type="primary"
                :disabled="pluginOperationBusy"
                @click="handleExecFront(row)"
              >
                <ArtSvgIcon icon="ri:download-line" class="mr-1" />点击安装
              </ElLink>
              <ElTag v-else-if="row.state === 1" type="success">已安装</ElTag>
              <ElTag v-else type="info">-</ElTag>
            </template>

            <!-- 后端依赖列 -->
            <template #composer="{ row }">
              <ElTag v-if="isLegacyFailedUpgradeRecovery(row)" type="info">-</ElTag>
              <ElLink
                v-else-if="row.composer_dependent_wait_install === 1"
                type="primary"
                :disabled="pluginOperationBusy"
                @click="handleExecBackend(row)"
              >
                <ArtSvgIcon icon="ri:download-line" class="mr-1" />点击安装
              </ElLink>
              <ElTag v-else-if="row.state === 1" type="success">已安装</ElTag>
              <ElTag v-else type="info">-</ElTag>
            </template>

            <!-- 操作列 -->
            <template #operation="{ row }">
              <ElSpace wrap>
                <ElLink
                  :type="isLegacyFailedUpgradeRecovery(row) ? 'warning' : 'info'"
                  :disabled="pluginOperationBusy"
                  @click="openLocalDetail(row)"
                >
                  <ArtSvgIcon icon="ri:file-info-line" class="mr-1" />
                  {{ isLegacyFailedUpgradeRecovery(row) ? '恢复处理' : '查看详情' }}
                </ElLink>
                <ElLink
                  v-if="canCleanupLocal(row)"
                  type="danger"
                  :disabled="pluginOperationBusy"
                  @click="openCleanupDialog(row)"
                >
                  <ArtSvgIcon icon="ri:delete-bin-2-line" class="mr-1" />
                  {{ row.cleanup_pending ? '继续清理' : '清理残留' }}
                </ElLink>
                <template v-if="!isLegacyFailedUpgradeRecovery(row)">
                  <ElLink
                    v-if="row.registration_candidate === 1"
                    type="primary"
                    :disabled="pluginOperationBusy"
                    @click="handleRegisterExisting(row)"
                  >
                    <ArtSvgIcon icon="ri:shield-check-line" class="mr-1" />登记现有插件
                  </ElLink>
                  <template v-else-if="isCompatibleUpgradeCandidate(row)">
                    <ElLink
                      type="primary"
                      :disabled="pluginOperationBusy"
                      @click="handleUpgradeCandidate(row)"
                    >
                      <ArtSvgIcon icon="ri:arrow-up-circle-line" class="mr-1" />确认升级
                    </ElLink>
                    <ElLink
                      v-if="!isPostgresqlLifecycleRecord(row)"
                      type="warning"
                      :disabled="pluginOperationBusy"
                      @click="handleDiscardCandidate(row)"
                    >
                      <ArtSvgIcon icon="ri:arrow-go-back-line" class="mr-1" />撤回升级包
                    </ElLink>
                  </template>
                  <template v-else-if="isLegacyRecoverableCandidate(row)">
                    <ElTag type="warning">这是较早版本上传的升级包，请先撤回后重新上传</ElTag>
                    <ElLink
                      type="warning"
                      :disabled="pluginOperationBusy"
                      @click="handleDiscardCandidate(row)"
                    >
                      <ArtSvgIcon icon="ri:arrow-go-back-line" class="mr-1" />撤回升级包
                    </ElLink>
                  </template>
                  <template v-else-if="isReadyUpgradeCandidate(row)">
                    <ElTag type="warning">
                      {{
                        isPostgresqlLifecycleRecord(row)
                          ? '与当前宿主版本不兼容，不能升级'
                          : '与当前宿主版本不兼容，仅可撤回升级包'
                      }}
                    </ElTag>
                    <ElLink
                      v-if="!isPostgresqlLifecycleRecord(row)"
                      type="warning"
                      :disabled="pluginOperationBusy"
                      @click="handleDiscardCandidate(row)"
                    >
                      <ArtSvgIcon icon="ri:arrow-go-back-line" class="mr-1" />撤回升级包
                    </ElLink>
                  </template>
                  <ElTag v-else-if="isUpgradeCandidateStage(row)" type="danger"
                    >升级包不完整，请联系管理员</ElTag
                  >
                  <ElPopconfirm
                    v-else-if="canInstallLocal(row)"
                    title="确定要安装当前插件吗?"
                    @confirm="handleInstall(row)"
                    confirm-button-text="确定"
                    cancel-button-text="取消"
                  >
                    <template #reference>
                      <ElLink type="warning" :disabled="pluginOperationBusy">
                        <ArtSvgIcon icon="ri:apps-2-add-line" class="mr-1" />安装
                      </ElLink>
                    </template>
                  </ElPopconfirm>
                  <ElPopconfirm
                    v-if="canUninstallLocal(row)"
                    title="确定要卸载当前插件吗?"
                    @confirm="handleUninstall(row)"
                    confirm-button-text="确定"
                    cancel-button-text="取消"
                  >
                    <template #reference>
                      <ElLink type="danger" :disabled="pluginOperationBusy">
                        <ArtSvgIcon icon="ri:delete-bin-5-line" class="mr-1" />卸载
                      </ElLink>
                    </template>
                  </ElPopconfirm>
                  <ElTag
                    v-if="
                      !canInstallLocal(row) &&
                      !canUninstallLocal(row) &&
                      row.registration_candidate !== 1 &&
                      !isUpgradeCandidateStage(row)
                    "
                    :type="row.state === 1 ? 'success' : 'warning'"
                  >
                    {{ localActionReason(row) }}
                  </ElTag>
                </template>
              </ElSpace>
            </template>
          </ArtTable>
        </ElTabPane>

        <!-- 插件仓库 Tab -->
        <ElTabPane label="插件仓库" name="repository">
          <div class="repository-toolbar">
            <ElInput
              v-model="repositoryKeyword"
              placeholder="搜索插件名称、标识、作者或简介"
              clearable
              class="repository-search"
            >
              <template #prefix>
                <ArtSvgIcon icon="ri:search-line" />
              </template>
            </ElInput>
            <ElButton
              :loading="repositoryLoading"
              :disabled="repositoryDownloading"
              @click="fetchRepositoryCatalog"
            >
              <template #icon>
                <ArtSvgIcon icon="ri:refresh-line" />
              </template>
              刷新仓库
            </ElButton>
            <ElPopover v-if="repositoryCatalog" placement="bottom-end" trigger="click" :width="360">
              <template #reference>
                <ElButton text>
                  <template #icon>
                    <ArtSvgIcon icon="ri:information-line" />
                  </template>
                  仓库来源
                </ElButton>
              </template>
              <ElDescriptions :column="1" size="small" border>
                <ElDescriptionsItem label="仓库">
                  {{ repositoryCatalog.repository }}
                </ElDescriptionsItem>
                <ElDescriptionsItem label="版本来源">
                  {{ repositoryCatalog.ref }}
                </ElDescriptionsItem>
              </ElDescriptions>
            </ElPopover>
          </div>

          <div v-if="repositoryLoading" class="repository-state" v-loading="true">
            正在读取插件仓库
          </div>
          <ElAlert
            v-else-if="repositoryError"
            type="error"
            :closable="false"
            title="插件仓库读取失败"
          >
            <div class="repository-error-row">
              <span>{{ repositoryError }}</span>
              <ElButton size="small" @click="fetchRepositoryCatalog">重新加载</ElButton>
            </div>
          </ElAlert>
          <ElEmpty
            v-else-if="repositoryLoaded && repositoryPlugins.length === 0"
            description="仓库暂未发布可用插件"
          />
          <ElEmpty
            v-else-if="repositoryLoaded && filteredRepositoryPlugins.length === 0"
            description="没有匹配当前关键词的插件"
          />
          <div v-else class="app-grid">
            <article
              v-for="item in filteredRepositoryPlugins"
              :key="item.app"
              class="repository-app-card"
            >
              <div class="repository-card-header">
                <div class="repository-plugin-icon" aria-hidden="true">
                  <ArtSvgIcon icon="ri:plug-line" />
                </div>
                <div class="repository-card-heading">
                  <ElTooltip :content="item.title" placement="top" :show-after="300">
                    <div class="repository-card-title">{{ item.title }}</div>
                  </ElTooltip>
                  <div class="repository-card-version">
                    {{ item.app }}
                    <template v-if="item.versions[0]">
                      · 仓库版本 v{{ item.versions[0].version }}
                    </template>
                  </div>
                </div>
              </div>
              <p class="repository-card-about">{{ item.about }}</p>
              <div class="repository-card-meta">
                <span class="repository-card-author">{{ item.author }}</span>
                <ElTooltip
                  :disabled="!item.local.reason"
                  :content="item.local.reason"
                  placement="top"
                >
                  <ElTag :type="repositoryLocalTagType(item.local)" size="small">
                    {{ repositoryLocalLabel(item.local) }}
                  </ElTag>
                </ElTooltip>
              </div>
              <div class="repository-card-footer">
                <div class="repository-card-secondary-actions">
                  <ElButton
                    v-if="item.versions[0]"
                    link
                    type="primary"
                    size="small"
                    @click="openRepositoryDocument(item, item.versions[0])"
                  >
                    查看文档
                  </ElButton>
                  <ElButton
                    link
                    type="primary"
                    size="small"
                    :disabled="item.versions.length === 0"
                    @click="showRepositoryVersions(item)"
                  >
                    {{ item.versions.length > 1 ? '其他版本' : '版本详情' }}
                  </ElButton>
                </div>
                <ElButton
                  v-if="item.versions[0]"
                  class="repository-card-primary-action"
                  size="small"
                  :type="repositoryActionType(item.versions[0].action)"
                  :loading="downloadingKey === repositoryVersionKey(item, item.versions[0])"
                  :disabled="repositoryActionDisabled(item, item.versions[0])"
                  @click="handleRepositoryVersionAction(item, item.versions[0])"
                >
                  {{ repositoryActionLabel(item.versions[0].action) }}
                </ElButton>
              </div>
            </article>
          </div>
        </ElTabPane>
      </ElTabs>
    </ElCard>

    <!-- 上传插件弹窗 -->
    <InstallForm
      ref="installFormRef"
      :disabled="pluginOperationBusy"
      :can-start-write="canStartUploadWrite"
      :after-upload="refreshAfterUpload"
      @busy-change="handleUploadBusyChange"
    />

    <!-- 单个插件详情与恢复处理 -->
    <ElDrawer
      v-model="localDetailVisible"
      :title="localDetailTitle"
      size="min(720px, 94vw)"
      @closed="closeLocalDetail"
    >
      <template v-if="selectedLocalRow">
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem label="插件">
            {{ selectedLocalRow.title || selectedLocalRow.app }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="标识">{{ selectedLocalRow.app }}</ElDescriptionsItem>
          <ElDescriptionsItem label="记录版本">
            {{ selectedLocalRow.version || '未知' }}
          </ElDescriptionsItem>
          <ElDescriptionsItem label="当前状态">
            <ElTag :type="stateTagType(selectedLocalRow)">
              {{ stateText(selectedLocalRow) }}
            </ElTag>
          </ElDescriptionsItem>
        </ElDescriptions>

        <ElAlert
          class="mt-4"
          :type="selectedLocalRow.state === 7 ? 'error' : 'info'"
          :closable="false"
          :title="localDetailReason(selectedLocalRow)"
        />
      </template>

      <section v-if="recoveryDetailRow" class="recovery-detail">
        <p class="failed-upgrade-steps" aria-label="升级恢复步骤">
          <span :class="{ current: recoveryStep(recoveryDetailRow) === 1 }"> 1 检查恢复条件 </span>
          <span class="step-arrow">→</span>
          <span :class="{ current: recoveryStep(recoveryDetailRow) === 2 }"> 2 准备插件包 </span>
          <span class="step-arrow">→</span>
          <span :class="{ current: recoveryStep(recoveryDetailRow) === 2 }"> 3 核对插件包 </span>
          <span class="step-arrow">→</span>
          <span :class="{ current: recoveryStep(recoveryDetailRow) === 2 }"> 4 恢复插件文件 </span>
          <span class="step-arrow">→</span>
          <span :class="{ current: recoveryStep(recoveryDetailRow) === 3 }"> 5 重新升级 </span>
        </p>
        <p class="failed-upgrade-meta">
          {{ recoveryVersionSummary(recoveryDetailRow) }}
        </p>

        <ElAlert
          v-if="sessionOf(recoveryDetailRow).errorMessage"
          class="mt-3"
          type="error"
          :closable="false"
          title="恢复未完成"
        >
          {{ sessionOf(recoveryDetailRow).errorMessage }}
        </ElAlert>
        <ElAlert
          v-else-if="sessionOf(recoveryDetailRow).phase === 'success'"
          class="mt-3"
          type="success"
          :closable="false"
        >
          {{ sessionOf(recoveryDetailRow).message }}
        </ElAlert>
        <ElAlert
          v-else-if="
            sessionOf(recoveryDetailRow).message &&
            (sessionOf(recoveryDetailRow).phase !== 'needs_verify' ||
              sessionOf(recoveryDetailRow).message === FAILED_UPGRADE_RUNTIME_RESTORED_MESSAGE) &&
            !sessionOf(recoveryDetailRow).blocked
          "
          class="mt-3"
          type="info"
          :closable="false"
        >
          {{ sessionOf(recoveryDetailRow).message }}
        </ElAlert>

        <div v-if="canRestoreRuntime(recoveryDetailRow)" class="failed-upgrade-actions">
          <ElButton :disabled="loading || !!listError || pluginOperationBusy" @click="getList()">
            刷新状态
          </ElButton>
          <ElButton
            type="primary"
            :disabled="pluginOperationBusy"
            :loading="sessionOf(recoveryDetailRow).phase === 'restoring_runtime'"
            @click="handleRestoreRuntime(recoveryDetailRow)"
          >
            恢复升级前文件
          </ElButton>
        </div>
        <div v-else-if="sessionOf(recoveryDetailRow).blocked" class="failed-upgrade-actions"></div>
        <div
          v-else-if="
            sessionOf(recoveryDetailRow).phase === 'needs_verify' ||
            sessionOf(recoveryDetailRow).phase === 'diagnosing'
          "
          class="failed-upgrade-actions"
        >
          <ElButton
            :disabled="loading || isRecoveryBusy(recoveryDetailRow) || pluginOperationBusy"
            @click="getList()"
          >
            刷新状态
          </ElButton>
          <ElButton
            :disabled="loading || !!listError || pluginOperationBusy"
            :loading="sessionOf(recoveryDetailRow).phase === 'diagnosing'"
            @click="handleInspectRecovery(recoveryDetailRow)"
          >
            检查恢复条件
          </ElButton>
        </div>
        <div v-else class="failed-upgrade-actions" v-loading="isRecoveryBusy(recoveryDetailRow)">
          <ElButton
            :disabled="loading || isRecoveryBusy(recoveryDetailRow) || pluginOperationBusy"
            @click="getList()"
          >
            刷新状态
          </ElButton>
          <template
            v-if="
              sessionOf(recoveryDetailRow).phase === 'needs_prepare' ||
              sessionOf(recoveryDetailRow).phase === 'preparing'
            "
          >
            <ElUpload
              accept=".zip"
              :auto-upload="false"
              :limit="1"
              :show-file-list="true"
              :disabled="isRecoveryBusy(recoveryDetailRow) || pluginOperationBusy"
              :on-change="onRecoveryDetailFileChange"
              :on-remove="onRecoveryDetailFileRemove"
            >
              <ElButton :disabled="isRecoveryBusy(recoveryDetailRow) || pluginOperationBusy">
                选择 ZIP 插件包
              </ElButton>
              <template #tip>
                <div class="failed-upgrade-upload-tip">仅接受 ZIP，且不超过 5MB</div>
              </template>
            </ElUpload>
            <ElButton
              type="primary"
              :disabled="
                isRecoveryBusy(recoveryDetailRow) ||
                pluginOperationBusy ||
                sessionOf(recoveryDetailRow).selectedFileName === ''
              "
              :loading="
                sessionOf(recoveryDetailRow).phase === 'preparing' ||
                sessionOf(recoveryDetailRow).phase === 'replacing'
              "
              @click="handlePrepareRecoveryCandidate(recoveryDetailRow)"
            >
              检查插件包
            </ElButton>
          </template>
          <ElButton
            v-if="
              sessionOf(recoveryDetailRow).phase === 'needs_gate_a' ||
              sessionOf(recoveryDetailRow).phase === 'verifying'
            "
            type="primary"
            :disabled="pluginOperationBusy"
            :loading="sessionOf(recoveryDetailRow).phase === 'verifying'"
            @click="handleVerifyRecovery(recoveryDetailRow)"
          >
            核对插件包
          </ElButton>
          <ElButton
            v-if="sessionOf(recoveryDetailRow).phase === 'retry_safe'"
            type="primary"
            :disabled="pluginOperationBusy"
            :loading="sessionOf(recoveryDetailRow).phase === 'replacing'"
            @click="handleReplaceRecoveryCandidate(recoveryDetailRow)"
          >
            恢复插件文件
          </ElButton>
          <ElButton
            v-if="
              recoveryStep(recoveryDetailRow) === 3 &&
              sessionOf(recoveryDetailRow).phase !== 'success'
            "
            type="primary"
            :disabled="pluginOperationBusy"
            :loading="sessionOf(recoveryDetailRow).phase === 'retrying'"
            @click="handleRetryRecovery(recoveryDetailRow)"
          >
            重新执行升级
          </ElButton>
        </div>
      </section>
    </ElDrawer>

    <!-- 终端弹窗 -->
    <TerminalBox ref="terminalRef" @success="getList" />

    <!-- 异常插件残留清理 -->
    <ElDialog
      v-model="cleanupVisible"
      title="清理异常插件残留"
      width="min(680px, 94vw)"
      :close-on-click-modal="!cleanupSubmitting && !cleanupPackageLoading && !cleanupReloading"
      :close-on-press-escape="!cleanupSubmitting && !cleanupPackageLoading && !cleanupReloading"
      :show-close="!cleanupSubmitting && !cleanupPackageLoading && !cleanupReloading"
      @close="invalidateCleanupRequest"
      @closed="resetCleanupDialog"
    >
      <div v-if="cleanupInspecting" class="cleanup-loading" v-loading="true">
        正在检查需要清理的内容
      </div>

      <ElResult
        v-else-if="cleanupCompleted"
        icon="success"
        :title="cleanupCompleted.restart_required ? '插件残留已清理' : '清理已完成，可以重新安装'"
      >
        <template #sub-title>
          <p>
            {{
              cleanupCompleted.restart_required
                ? '文件已清理，请重载后端服务使变更生效。'
                : '插件状态已刷新。'
            }}
          </p>
          <p v-if="cleanupCompleted.archive">文件归档：{{ cleanupCompleted.archive }}</p>
          <ElAlert
            v-if="cleanupCompleted.warning"
            class="mt-3"
            type="warning"
            :closable="false"
            :title="cleanupCompleted.warning"
          />
          <ElAlert
            v-if="cleanupReloadError"
            class="mt-3"
            type="error"
            :closable="false"
            :title="cleanupReloadError"
          />
        </template>
      </ElResult>

      <template v-else>
        <ElAlert v-if="cleanupError" type="error" :closable="false" title="需要重新检查">
          <div class="cleanup-error-row">
            <span>{{ cleanupError }}</span>
            <ElButton size="small" :disabled="cleanupSubmitting" @click="inspectCleanup">
              重新检查
            </ElButton>
          </div>
        </ElAlert>

        <details
          v-if="!cleanupRangeLocked"
          class="cleanup-section cleanup-package"
          :open="cleanupInspection === null"
        >
          <summary>
            {{ cleanupInspection ? '更换清理包（可选）' : '补充清理范围' }}
          </summary>
          <p class="cleanup-empty">
            可选择同一插件的已发布版本补充识别残留；此操作不会安装插件，也不会执行包内 SQL。
          </p>
          <div v-if="repositoryLoading" class="cleanup-package-state" v-loading="true">
            正在读取插件仓库
          </div>
          <ElAlert
            v-else-if="repositoryError"
            class="mt-3"
            type="error"
            :closable="false"
            title="插件仓库读取失败"
          >
            <ElButton size="small" @click="fetchRepositoryCatalog">重新读取仓库</ElButton>
          </ElAlert>
          <p v-else-if="cleanupRepositoryVersions.length === 0" class="cleanup-empty mt-3">
            仓库中没有该插件的已发布版本，可刷新仓库后重试。
          </p>
          <div v-else class="cleanup-package-action">
            <ElSelect
              v-model="cleanupPackageVersion"
              :disabled="cleanupPackageLoading"
              aria-label="补充清理包版本"
            >
              <ElOption
                v-for="item in cleanupRepositoryVersions"
                :key="item.version"
                :label="`v${item.version}${item.version === cleanupTargetVersion ? '（安装记录版本）' : ''}`"
                :value="item.version"
              />
            </ElSelect>
            <ElButton
              type="primary"
              :loading="cleanupPackageLoading"
              :disabled="cleanupPackageVersion === '' || pluginOperationBusy"
              @click="prepareCleanupPackage"
            >
              使用此版本检查清理范围
            </ElButton>
          </div>
        </details>

        <template v-if="cleanupInspection">
          <ElDescriptions :column="1" border>
            <ElDescriptionsItem label="插件">
              {{ cleanupTargetTitle || cleanupInspection.app }}
            </ElDescriptionsItem>
            <ElDescriptionsItem label="插件标识">{{ cleanupInspection.app }}</ElDescriptionsItem>
            <ElDescriptionsItem label="版本">
              <div>安装记录版本 {{ cleanupInspection.version || '未知' }}</div>
              <div v-if="cleanupInspection.cleanup_package_version">
                补充清理包 {{ cleanupInspection.cleanup_package_version }}
              </div>
            </ElDescriptionsItem>
          </ElDescriptions>

          <ElAlert
            v-if="cleanupInspection.phase === 'files_pending'"
            class="mt-3"
            type="warning"
            :closable="false"
            title="数据库清理步骤已经完成，请继续完成文件清理。"
          />

          <section class="cleanup-section">
            <h4>现存业务表（{{ cleanupInspection.tables.length }}）</h4>
            <details v-if="cleanupInspection.tables.length" class="cleanup-residue-details">
              <summary>展开查看业务表</summary>
              <div class="cleanup-residue-scroll cleanup-tags">
                <ElTag v-for="table in cleanupInspection.tables" :key="table" type="danger">
                  {{ table }}
                </ElTag>
              </div>
            </details>
            <p v-else class="cleanup-empty">没有发现插件业务表残留。</p>
          </section>

          <section class="cleanup-section">
            <h4>现存菜单（{{ cleanupInspection.menus.length }}）</h4>
            <details v-if="cleanupInspection.menus.length" class="cleanup-residue-details">
              <summary>展开查看菜单</summary>
              <div class="cleanup-residue-scroll">
                <ElDescriptions :column="1" border size="small">
                  <ElDescriptionsItem
                    v-for="menu in cleanupInspection.menus"
                    :key="menu.id"
                    :label="menu.name || menu.code || menu.id"
                  >
                    {{ menu.code || '无权限标识' }} · ID {{ menu.id }}
                  </ElDescriptionsItem>
                </ElDescriptions>
              </div>
            </details>
            <p v-else class="cleanup-empty">没有发现插件菜单残留。</p>
          </section>

          <section class="cleanup-section">
            <h4>将归档的文件路径</h4>
            <ul v-if="cleanupInspection.paths.length" class="cleanup-paths">
              <li v-for="path in cleanupInspection.paths" :key="path">{{ path }}</li>
            </ul>
            <p v-else class="cleanup-empty">没有发现需要归档的插件文件。</p>
          </section>

          <ElAlert class="mt-3" type="warning" :closable="false" title="确认清理影响">
            本次操作会删除上面列出的插件业务表和菜单数据。插件文件及旧包会被归档，但归档不包含数据库备份，无法据此恢复已删除的数据库数据。
          </ElAlert>

          <div class="cleanup-confirmation">
            <label for="cleanup-confirm-app">
              输入插件标识 <strong>{{ cleanupInspection.app }}</strong> 以确认
            </label>
            <ElInput
              id="cleanup-confirm-app"
              v-model="cleanupConfirmation"
              :disabled="cleanupSubmitting"
              autocomplete="off"
              placeholder="请输入插件标识"
              @keyup.enter="submitCleanup"
            />
          </div>
        </template>
      </template>

      <template #footer>
        <template v-if="cleanupCompleted">
          <ElButton :disabled="cleanupReloading" @click="cleanupVisible = false">关闭</ElButton>
          <ElButton
            v-if="cleanupCompleted.restart_required"
            type="warning"
            :loading="cleanupReloading"
            @click="reloadCleanupBackend"
          >
            重载后端服务
          </ElButton>
          <ElButton
            type="primary"
            :disabled="cleanupReloading || cleanupCompleted.restart_required"
            @click="goToRepositoryAfterCleanup"
          >
            去插件仓库
          </ElButton>
        </template>
        <template v-else>
          <ElButton
            :disabled="cleanupSubmitting || cleanupPackageLoading"
            @click="cleanupVisible = false"
          >
            取消
          </ElButton>
          <ElButton
            v-if="cleanupInspection"
            type="danger"
            :loading="cleanupSubmitting"
            :disabled="!canSubmitCleanup"
            @click="submitCleanup"
          >
            {{
              cleanupInspection.phase === 'files_pending' ? '继续完成清理' : '清理并允许重新安装'
            }}
          </ElButton>
        </template>
      </template>
    </ElDialog>

    <!-- 仓库版本选择对话框 -->
    <ElDialog
      v-model="repositoryVersionVisible"
      :title="'选择版本 - ' + (currentRepositoryPlugin?.title || '')"
      width="680"
      :close-on-click-modal="!repositoryDownloading"
      :close-on-press-escape="!repositoryDownloading"
      :show-close="!repositoryDownloading"
    >
      <ElAlert
        v-if="repositoryActionError"
        class="mb-3"
        type="error"
        :closable="false"
        title="插件操作未完成"
      >
        <div class="repository-error-row">
          <span>{{ repositoryActionError }}</span>
          <ElButton size="small" @click="goToPluginManagement">去插件管理</ElButton>
        </div>
      </ElAlert>
      <div class="version-list">
        <div
          v-for="item in currentRepositoryPlugin?.versions || []"
          :key="item.version"
          class="version-item"
        >
          <div>
            <div class="version-info-row">
              <span class="version-name">v{{ item.version }}</span>
              <ElTag size="small" type="info">{{ item.tag }}</ElTag>
            </div>
            <div class="version-compatibility">
              兼容 SandAdmin {{ item.host_min
              }}{{ item.host_max ? ` 至 ${item.host_max}` : ' 及以上' }}
            </div>
            <div class="version-remark">{{ item.notes }}</div>
            <div class="version-action-reason">{{ item.action_reason }}</div>
          </div>
          <ElSpace wrap>
            <ElButton size="small" @click="openRepositoryDocument(currentRepositoryPlugin, item)">
              查看文档
            </ElButton>
            <ElButton
              :type="repositoryActionType(item.action)"
              size="small"
              :loading="downloadingKey === repositoryVersionKey(currentRepositoryPlugin, item)"
              :disabled="repositoryActionDisabled(currentRepositoryPlugin, item)"
              @click="handleRepositoryVersionAction(currentRepositoryPlugin, item)"
            >
              {{ repositoryActionLabel(item.action) }}
            </ElButton>
          </ElSpace>
        </div>
        <ElEmpty
          v-if="(currentRepositoryPlugin?.versions.length || 0) === 0"
          description="暂无可用版本"
        />
      </div>
    </ElDialog>

    <!-- 发布包文档 -->
    <ElDrawer
      v-model="repositoryDocumentVisible"
      :title="repositoryDocumentTitle"
      size="min(760px, 92vw)"
      @closed="closeRepositoryDocument"
    >
      <div v-if="repositoryDocumentLoading" class="repository-state" v-loading="true">
        正在读取发布包文档
      </div>
      <ElAlert
        v-else-if="repositoryDocumentError"
        type="error"
        :closable="false"
        title="文档读取失败"
      >
        <div class="repository-error-row">
          <span>{{ repositoryDocumentError }}</span>
          <ElButton size="small" @click="retryRepositoryDocument">重新加载</ElButton>
        </div>
      </ElAlert>
      <ElEmpty
        v-else-if="repositoryDocumentMarkdown === ''"
        description="该版本未提供 README 文档"
      />
      <pre v-else class="repository-document">{{ repositoryDocumentMarkdown }}</pre>
    </ElDrawer>
  </div>
</template>

<script setup lang="ts">
  import { ref, reactive, computed, onMounted, watch } from 'vue'
  import { ElMessage, ElMessageBox } from 'element-plus'
  import type { ColumnOption } from '@/types'
  import type { UploadFile } from 'element-plus'
  import sandpackageApi, {
    type VersionInfo,
    type RepositoryCatalog,
    type RepositoryPlugin,
    type RepositoryPluginLocal,
    type RepositoryPluginVersion,
    type RepositoryVersionAction,
    type CleanupInspection,
    type CleanupResult
  } from '../api/index'
  import InstallForm from './install-box.vue'
  import TerminalBox from './terminal.vue'
  import { TaskStatus, useTerminalStore } from '../store/terminal'
  import {
    FAILED_UPGRADE_BLOCKED_MESSAGE,
    FAILED_UPGRADE_FAILURE_MESSAGE,
    FAILED_UPGRADE_LIST_ERROR_MESSAGE,
    FAILED_UPGRADE_RUNTIME_RESTORED_MESSAGE,
    FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE,
    FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE,
    FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE,
    FailedUpgradeRecoveryClosedError,
    assertFailedUpgradeZip,
    canShowRecoveryWriteActions,
    clearRecoveryWriteState,
    createFailedUpgradeSession,
    exactReplaceConfirmation,
    exactRestoreRuntimeConfirmation,
    exactRetryConfirmation,
    isFailedUpgradeRecovery,
    isRuntimeRestoreRequiredRow,
    isRuntimeRestoreVerificationPresentation,
    isRecoveryWriteBusy,
    inspectFailedUpgradeRecovery,
    prepareFailedUpgradeReplacement,
    readFailedUpgradeFromVersion,
    readFailedUpgradeReason,
    readRecoveryErrorMessage,
    readRowVersionTuple,
    reconcileFailedUpgradeSession,
    recoveryStepNumber,
    replaceFailedUpgradeCandidate,
    restoreRuntimeFromBackup,
    retryFailedUpgrade,
    shouldHideGlobalPluginWrites,
    tuplesMatch,
    verifyFailedUpgradeRecovery,
    type FailedUpgradeRecoverySession,
    type RecoveryWriteGate,
    type SandpackageInstallRow
  } from './failed-upgrade-recovery'

  interface InstallFormBox {
    open: () => void
  }

  interface TerminalBoxExpose {
    open: () => void
    frontInstall: (extend: string) => void
    backendInstall: (extend: string) => void
  }

  interface RepositoryDocumentTarget {
    app: string
    title: string
    version: string
    sha256: string
  }

  type LocalWriteOwner =
    | ''
    | 'install'
    | 'uninstall'
    | 'upgrade'
    | 'discard'
    | 'register'
    | 'upload'

  // ========== 基础状态 ==========
  const activeTab = ref('repository')
  const version = ref<VersionInfo>({})
  const loading = ref(false)
  const listError = ref('')
  const localDetailVisible = ref(false)
  const selectedLocalApp = ref('')
  const localDetailRecoveryExpected = ref(false)
  const installFormRef = ref<InstallFormBox | null>(null)
  const terminalRef = ref<TerminalBoxExpose | null>(null)
  const installList = ref<SandpackageInstallRow[]>([])
  const recoverySessions = reactive<Record<string, FailedUpgradeRecoverySession>>({})
  const recoveryFiles = new Map<string, File>()
  const terminalStore = useTerminalStore()
  const localWriteOwner = ref<LocalWriteOwner>('')
  const terminalLaunchPending = ref(false)
  const cleanupVisible = ref(false)
  const cleanupTargetApp = ref('')
  const cleanupTargetTitle = ref('')
  const cleanupTargetVersion = ref('')
  const cleanupTargetPending = ref(false)
  const cleanupInspection = ref<CleanupInspection | null>(null)
  const cleanupCompleted = ref<CleanupResult | null>(null)
  const cleanupInspecting = ref(false)
  const cleanupSubmitting = ref(false)
  const cleanupPackageLoading = ref(false)
  const cleanupPackageVersion = ref('')
  const cleanupRangeLocked = ref(false)
  const cleanupReloading = ref(false)
  const cleanupReloadError = ref('')
  const cleanupError = ref('')
  const cleanupConfirmation = ref('')
  let cleanupRequestId = 0

  const isPostgresqlLifecycleRecord = (row: SandpackageInstallRow): boolean =>
    row.lifecycle_driver === 'saipackage-pg-v1'

  const isLegacyFailedUpgradeRecovery = (row: SandpackageInstallRow): boolean =>
    row.cleanup_pending !== true &&
    !isPostgresqlLifecycleRecord(row) &&
    isFailedUpgradeRecovery(row)

  const failedUpgradeRows = computed(() =>
    installList.value.filter((row) => isLegacyFailedUpgradeRecovery(row))
  )

  const selectedLocalRow = computed(
    () => installList.value.find((row) => row.app === selectedLocalApp.value) ?? null
  )

  const recoveryDetailRow = computed(() => {
    const row = selectedLocalRow.value
    return row && isLegacyFailedUpgradeRecovery(row) ? row : null
  })

  const localDetailTitle = computed(() => {
    const row = selectedLocalRow.value
    if (!row) return '插件详情'
    return `${row.title || row.app}${recoveryDetailRow.value ? '恢复处理' : '详情'}`
  })

  const recoveryWriteGate = computed<RecoveryWriteGate>(() => ({
    listLoading: loading.value,
    listFailed: listError.value !== ''
  }))

  const hideGlobalPluginWrites = computed(() => {
    if (loading.value || listError.value !== '') return true
    return shouldHideGlobalPluginWrites(failedUpgradeRows.value, recoverySessions)
  })

  const sessionOf = (row: SandpackageInstallRow): FailedUpgradeRecoverySession => {
    const existing = recoverySessions[row.app]
    if (existing) return existing
    const created = createFailedUpgradeSession(
      row.app,
      readFailedUpgradeFromVersion(row),
      row.version
    )
    created.message = readFailedUpgradeReason(row)
    recoverySessions[row.app] = created
    return created
  }

  const isRuntimeRestoreRequired = (row: SandpackageInstallRow): boolean =>
    isRuntimeRestoreRequiredRow(row)

  const canRestoreRuntime = (row: SandpackageInstallRow): boolean => {
    const current = sessionOf(row)
    return isRuntimeRestoreRequired(row) || (current.runtimeDrift && current.runtimeRestoreAllowed)
  }

  const lockAllRecoveryWrites = (): void => {
    for (const session of Object.values(recoverySessions)) {
      if (!session || session.phase === 'success') continue
      clearRecoveryWriteState(session, {
        phase: session.blocked ? 'blocked' : 'needs_verify',
        blocked: session.blocked,
        message: session.blocked ? FAILED_UPGRADE_BLOCKED_MESSAGE : FAILED_UPGRADE_FAILURE_MESSAGE
      })
    }
    recoveryFiles.clear()
  }

  const recoveryReason = (row: SandpackageInstallRow): string => {
    const current = recoverySessions[row.app]
    if (current?.blocked && current.message) return current.message
    return readFailedUpgradeReason(row)
  }

  const recoveryFromVersion = (row: SandpackageInstallRow): string => {
    return sessionOf(row).fromVersion || readFailedUpgradeFromVersion(row)
  }

  const recoveryVersionSummary = (row: SandpackageInstallRow): string => {
    const fromVersion = recoveryFromVersion(row)
    return fromVersion
      ? `记录版本：${fromVersion} → ${row.version}`
      : `记录版本：${row.version || '未知'}`
  }

  const recoveryStep = (row: SandpackageInstallRow): 1 | 2 | 3 =>
    recoveryStepNumber(sessionOf(row).phase)

  const isRecoveryBusy = (row: SandpackageInstallRow): boolean =>
    isRecoveryWriteBusy(sessionOf(row).phase)

  const syncFailedUpgradeSessions = (rows: SandpackageInstallRow[]): void => {
    const failedApps = new Set<string>()
    for (const row of rows) {
      if (!isLegacyFailedUpgradeRecovery(row)) continue
      failedApps.add(row.app)
      reconcileFailedUpgradeSession(sessionOf(row), row)
      recoveryFiles.delete(row.app)
    }
    for (const app of Object.keys(recoverySessions)) {
      if (failedApps.has(app)) {
        continue
      }
      delete recoverySessions[app]
      recoveryFiles.delete(app)
    }
  }

  // ========== 本地安装相关 ==========
  const handleUpload = () => {
    if (pluginOperationBusy.value) return
    installFormRef.value?.open()
  }

  const acquireLocalWrite = (owner: Exclude<LocalWriteOwner, '' | 'upload'>): boolean => {
    if (pluginOperationBusy.value) return false
    localWriteOwner.value = owner
    return true
  }

  const releaseLocalWrite = (owner: Exclude<LocalWriteOwner, ''>): void => {
    if (localWriteOwner.value === owner) localWriteOwner.value = ''
  }

  const canStartUploadWrite = (): boolean => !pluginOperationBusy.value

  const handleUploadBusyChange = (busy: boolean): void => {
    if (busy) {
      localWriteOwner.value = 'upload'
      return
    }
    releaseLocalWrite('upload')
  }

  const refreshAfterUpload = async (): Promise<void> => {
    await getList()
  }

  const openLocalDetail = (row: SandpackageInstallRow): void => {
    selectedLocalApp.value = row.app
    localDetailRecoveryExpected.value = isLegacyFailedUpgradeRecovery(row)
    localDetailVisible.value = true
  }

  const closeLocalDetail = (): void => {
    localDetailVisible.value = false
    selectedLocalApp.value = ''
    localDetailRecoveryExpected.value = false
  }

  const rejectOrdinaryAction = (record: SandpackageInstallRow): boolean => {
    if (record.ordinary_actions_blocked !== true) return false
    ElMessage.warning(
      isLegacyFailedUpgradeRecovery(record)
        ? FAILED_UPGRADE_FAILURE_MESSAGE
        : localDetailReason(record)
    )
    return true
  }

  const promptConfirmation = async (
    title: string,
    description: string,
    expected: string,
    confirmButtonText: string
  ): Promise<string | null> => {
    try {
      const result: unknown = await ElMessageBox.prompt(description, title, {
        confirmButtonText,
        cancelButtonText: '取消',
        inputPlaceholder: expected,
        inputValidator: (value: string) => value === expected || '确认内容不匹配'
      })
      if (
        typeof result === 'object' &&
        result !== null &&
        'value' in result &&
        typeof result.value === 'string'
      ) {
        return result.value
      }
      return null
    } catch {
      return null
    }
  }

  /** 第一步：诊断持久失败状态；有运行文件漂移时只允许恢复。 */
  const handleInspectRecovery = async (record: SandpackageInstallRow): Promise<void> => {
    if (pluginOperationBusy.value) return
    if (recoveryWriteGate.value.listLoading || recoveryWriteGate.value.listFailed) return
    const expected = readRowVersionTuple(record)
    const current = sessionOf(record)
    current.phase = 'diagnosing'
    current.errorMessage = ''
    try {
      const result = await inspectFailedUpgradeRecovery(expected)
      await getList(true)
      const refreshed = installList.value.find((row) => row.app === expected.app)
      if (!refreshed || !tuplesMatch(readRowVersionTuple(refreshed), expected)) {
        throw new FailedUpgradeRecoveryClosedError(
          'recoverable',
          FAILED_UPGRADE_VERIFY_MISMATCH_MESSAGE
        )
      }
      const refreshedSession = sessionOf(refreshed)
      refreshedSession.runtimeDrift = result.runtimeDrift
      refreshedSession.runtimeRestoreAllowed =
        result.recoveryMode === 'runtime_restore_required' && result.runtimeDrift
      refreshedSession.blocked = false
      refreshedSession.phase = result.runtimeDrift ? 'needs_verify' : 'needs_prepare'
      refreshedSession.message = result.message
      ElMessage.success(result.message)
    } catch (error: unknown) {
      current.phase = 'error'
      current.errorMessage = readRecoveryErrorMessage(
        error,
        FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
      )
    }
  }

  /** 第四步 Gate A：只读核验已封存 replacement；失败不展示替换/重试按钮。 */
  const handleVerifyRecovery = async (record: SandpackageInstallRow): Promise<void> => {
    if (pluginOperationBusy.value) return
    if (recoveryWriteGate.value.listLoading || recoveryWriteGate.value.listFailed) return
    const expected = readRowVersionTuple(record)
    const current = sessionOf(record)
    current.phase = 'verifying'
    current.errorMessage = ''
    try {
      if (current.replacementId === '' || current.profileHash === '') {
        throw new FailedUpgradeRecoveryClosedError(
          'recoverable',
          FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
        )
      }
      const result = await verifyFailedUpgradeRecovery(
        expected,
        current.replacementId,
        current.profileHash
      )
      current.blocked = false
      current.phase = 'retry_safe'
      current.app = result.app
      current.fromVersion = result.fromVersion
      current.toVersion = result.toVersion
      current.message = result.message
      current.replaceConfirmation = exactReplaceConfirmation(result.app, result.toVersion)
      current.retryConfirmation = exactRetryConfirmation(
        result.app,
        result.fromVersion,
        result.toVersion
      )
      ElMessage.success(result.message)
    } catch (error: unknown) {
      const closed = error instanceof FailedUpgradeRecoveryClosedError ? error : undefined
      if (closed?.kind === 'blocked') {
        clearRecoveryWriteState(current, {
          phase: 'blocked',
          blocked: true,
          message: FAILED_UPGRADE_BLOCKED_MESSAGE
        })
        return
      }
      current.blocked = false
      current.phase = 'error'
      current.replacementId = ''
      current.selectedFileName = ''
      current.replaceConfirmation = exactReplaceConfirmation(expected.app, expected.toVersion)
      current.retryConfirmation = exactRetryConfirmation(
        expected.app,
        expected.fromVersion,
        expected.toVersion
      )
      current.errorMessage = readRecoveryErrorMessage(
        error,
        FAILED_UPGRADE_VERIFY_INCOMPLETE_MESSAGE
      )
      current.message = FAILED_UPGRADE_FAILURE_MESSAGE
    }
  }

  const handleRestoreRuntime = async (record: SandpackageInstallRow): Promise<void> => {
    if (pluginOperationBusy.value) return
    if (!canRestoreRuntime(record) || loading.value || listError.value !== '') return
    const expected = readRowVersionTuple(record)
    const confirmation = exactRestoreRuntimeConfirmation(expected.app, expected.fromVersion)
    const typed = await promptConfirmation(
      '恢复升级前运行文件',
      '系统只会隔离当前运行文件并恢复已核验备份，不会修改待升级插件包、备份、登记信息或数据库。请输入下方确认内容。',
      confirmation,
      '确认恢复'
    )
    if (typed === null) return
    const current = sessionOf(record)
    current.phase = 'restoring_runtime'
    current.errorMessage = ''
    try {
      const result = await restoreRuntimeFromBackup({
        appName: expected.app,
        confirmation: typed,
        expected
      })
      // The prompt does not retain a value, and this clears every staged
      // replacement/retry input before the only permitted next path is loaded.
      clearRecoveryWriteState(current, {
        phase: 'needs_verify',
        blocked: false,
        message: result.message
      })
      recoveryFiles.delete(expected.app)
      ElMessage.success(result.message)
      await getList(true)
      const refreshed = installList.value.find((row) => row.app === expected.app)
      if (
        !refreshed ||
        !isRuntimeRestoreVerificationPresentation(refreshed) ||
        !tuplesMatch(readRowVersionTuple(refreshed), expected)
      ) {
        ElMessage.warning(FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE)
        return
      }
      const refreshedSession = sessionOf(refreshed)
      clearRecoveryWriteState(refreshedSession, {
        // The durable restore response and the freshly loaded list both grant
        // only the next ZIP preflight action. Do not repeat diagnosis or infer
        // any later write permission from the previous runtime-drift session.
        phase: 'needs_prepare',
        blocked: false,
        message: result.message
      })
    } catch (error: unknown) {
      current.phase = 'error'
      current.errorMessage = readRecoveryErrorMessage(
        error,
        FAILED_UPGRADE_RUNTIME_RESTORE_UNCONFIRMED_MESSAGE
      )
    }
  }

  const onRecoveryFileChange = (record: SandpackageInstallRow, uploadFile: UploadFile): void => {
    const raw = uploadFile.raw
    if (!raw) return
    const problem = assertFailedUpgradeZip(raw)
    if (problem) {
      ElMessage.warning(problem)
      onRecoveryFileRemove(record)
      return
    }
    recoveryFiles.set(record.app, raw)
    const current = sessionOf(record)
    current.selectedFileName = raw.name
    current.errorMessage = ''
  }

  const onRecoveryFileRemove = (record: SandpackageInstallRow): void => {
    recoveryFiles.delete(record.app)
    const current = recoverySessions[record.app]
    if (current) {
      current.selectedFileName = ''
      if (current.phase === 'needs_gate_a') current.phase = 'needs_prepare'
      current.replacementId = ''
      current.profileHash = ''
    }
  }

  const onRecoveryDetailFileChange = (uploadFile: UploadFile): void => {
    if (recoveryDetailRow.value) onRecoveryFileChange(recoveryDetailRow.value, uploadFile)
  }

  const onRecoveryDetailFileRemove = (): void => {
    if (recoveryDetailRow.value) onRecoveryFileRemove(recoveryDetailRow.value)
  }

  /**
   * 第二步：只封存并预检 ZIP。预检成功后才能进入只读 Gate A。
   */
  const handlePrepareRecoveryCandidate = async (record: SandpackageInstallRow): Promise<void> => {
    if (pluginOperationBusy.value) return
    const current = sessionOf(record)
    if (
      recoveryWriteGate.value.listLoading ||
      recoveryWriteGate.value.listFailed ||
      current.phase !== 'needs_prepare'
    )
      return
    const expected = {
      app: current.app,
      fromVersion: current.fromVersion,
      toVersion: current.toVersion
    }
    const file = recoveryFiles.get(record.app)
    if (!file) {
      ElMessage.warning('请先选择 ZIP 插件包')
      return
    }
    const problem = assertFailedUpgradeZip(file)
    if (problem) {
      ElMessage.warning(problem)
      return
    }
    current.phase = 'preparing'
    current.errorMessage = ''
    try {
      const prepared = await prepareFailedUpgradeReplacement(expected, file)
      current.replacementId = prepared.replacementId
      current.profileHash = prepared.profileHash
      current.phase = 'needs_gate_a'
      current.message = prepared.message
      ElMessage.success(prepared.message)
      await getList(true)
    } catch (error: unknown) {
      current.phase = 'needs_prepare'
      current.errorMessage = readRecoveryErrorMessage(
        error,
        '插件文件恢复未完成，请重新选择插件包后再试'
      )
    }
  }

  /** 第五步：确认后替换已通过 Gate A 的候选；成功只由后续列表确认。 */
  const handleReplaceRecoveryCandidate = async (record: SandpackageInstallRow): Promise<void> => {
    if (pluginOperationBusy.value) return
    const current = sessionOf(record)
    if (
      !canShowRecoveryWriteActions(current, recoveryWriteGate.value) ||
      current.phase !== 'retry_safe' ||
      current.replacementId === ''
    )
      return
    const expected = {
      app: current.app,
      fromVersion: current.fromVersion,
      toVersion: current.toVersion
    }
    const confirmation = exactReplaceConfirmation(expected.app, expected.toVersion)
    const typed = await promptConfirmation(
      '恢复插件文件',
      '系统只会恢复本次失败升级使用的插件文件，不会执行安装或卸载。请输入下方确认内容。',
      confirmation,
      '确认替换'
    )
    if (typed === null) return
    current.phase = 'replacing'
    current.errorMessage = ''
    try {
      const message = await replaceFailedUpgradeCandidate({
        appName: expected.app,
        replacementId: current.replacementId,
        confirmation: typed,
        expected
      })
      current.message = message
      ElMessage.success(message)
      await getList(true)
    } catch (error: unknown) {
      current.phase = 'retry_safe'
      current.errorMessage = readRecoveryErrorMessage(error, '插件文件恢复未完成，请刷新后重新检查')
    }
  }

  /**
   * 第三步：按 RETRY app@from->to 确认后重新执行升级。
   */
  const handleRetryRecovery = async (record: SandpackageInstallRow): Promise<void> => {
    if (pluginOperationBusy.value) return
    const current = sessionOf(record)
    if (
      !canShowRecoveryWriteActions(current, recoveryWriteGate.value) ||
      current.phase === 'success'
    )
      return
    const expected = {
      app: current.app,
      fromVersion: current.fromVersion,
      toVersion: current.toVersion
    }
    const confirmation = exactRetryConfirmation(
      expected.app,
      expected.fromVersion,
      expected.toVersion
    )
    current.retryConfirmation = confirmation
    const typed = await promptConfirmation(
      '重新执行升级',
      '系统将重新执行升级，不会把它当作全新安装。请输入下方确认内容。',
      confirmation,
      '确认升级'
    )
    if (typed === null) return
    current.phase = 'retrying'
    current.errorMessage = ''
    try {
      const message = await retryFailedUpgrade({
        appName: expected.app,
        confirmation: typed,
        expected
      })
      current.phase = 'success'
      current.blocked = false
      current.message = message
      ElMessage.success(message)
      await getList(true)
    } catch (error: unknown) {
      clearRecoveryWriteState(current, {
        phase: 'error',
        blocked: false,
        message: FAILED_UPGRADE_FAILURE_MESSAGE
      })
      current.errorMessage = readRecoveryErrorMessage(error, '重新执行升级未完成，请刷新后重试')
    }
  }

  const checkVersionCompatibility = (
    support: string | undefined,
    hostVersion: string | undefined
  ): boolean => {
    if (!support || !hostVersion) return false

    const match = hostVersion.match(
      /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/
    )
    if (!match) return false

    const prerelease = match[4]
    if (
      prerelease &&
      prerelease.split('.').some((item) => /^\d+$/.test(item) && !/^(0|[1-9]\d*)$/.test(item))
    ) {
      return false
    }

    const supportTokens = support.split('|')
    if (
      supportTokens.length === 0 ||
      supportTokens.some((token) => !/^(?:\d+\.x|>=\d+\.\d+\.\d+)$/.test(token))
    ) {
      return false
    }
    return supportTokens.some((token) => {
      if (token === `${match[1]}.x`) return true
      if (!token.startsWith('>=')) return false
      const minimum = token.slice(2).split('.').map(Number)
      const host = [Number(match[1]), Number(match[2]), Number(match[3])]
      for (let i = 0; i < 3; i++) {
        if (host[i] > minimum[i]) return true
        if (host[i] < minimum[i]) return false
      }
      return !prerelease
    })
  }

  const canInstallLocal = (record: SandpackageInstallRow): boolean =>
    record.ordinary_actions_blocked !== true &&
    (record.state === 0 || (record.state === 2 && record.update !== 1))

  const canUninstallLocal = (record: SandpackageInstallRow): boolean =>
    record.state === 1 && record.ordinary_actions_blocked !== true

  const canCleanupLocal = (record: SandpackageInstallRow): boolean =>
    record.state === 7 || record.cleanup_pending === true

  const canSubmitCleanup = computed(
    () =>
      cleanupInspection.value !== null &&
      cleanupConfirmation.value === cleanupInspection.value.app &&
      !cleanupSubmitting.value
  )

  const cleanupErrorMessage = (error: unknown, fallback: string): string =>
    error instanceof Error && error.message.trim() !== '' ? error.message : fallback

  const invalidateCleanupRequest = (): void => {
    cleanupRequestId += 1
    cleanupInspecting.value = false
  }

  const resetCleanupDialog = (): void => {
    invalidateCleanupRequest()
    cleanupVisible.value = false
    cleanupTargetApp.value = ''
    cleanupTargetTitle.value = ''
    cleanupTargetVersion.value = ''
    cleanupTargetPending.value = false
    cleanupInspection.value = null
    cleanupCompleted.value = null
    cleanupSubmitting.value = false
    cleanupPackageLoading.value = false
    cleanupPackageVersion.value = ''
    cleanupRangeLocked.value = false
    cleanupReloading.value = false
    cleanupReloadError.value = ''
    cleanupError.value = ''
    cleanupConfirmation.value = ''
  }

  const inspectCleanup = async (): Promise<void> => {
    const app = cleanupTargetApp.value
    if (app === '' || cleanupSubmitting.value) return
    const requestId = ++cleanupRequestId
    cleanupInspecting.value = true
    cleanupInspection.value = null
    cleanupError.value = ''
    cleanupConfirmation.value = ''
    try {
      const result = await sandpackageApi.inspectCleanup({ appName: app })
      if (requestId !== cleanupRequestId || !cleanupVisible.value) return
      if (result.app !== app) {
        throw new Error('清理检查结果与当前插件不一致，请重新检查')
      }
      cleanupInspection.value = result
      cleanupRangeLocked.value =
        cleanupRangeLocked.value || cleanupTargetPending.value || result.phase === 'files_pending'
    } catch (error: unknown) {
      if (requestId !== cleanupRequestId || !cleanupVisible.value) return
      cleanupError.value = cleanupErrorMessage(error, '清理检查失败，请稍后重试')
    } finally {
      if (requestId === cleanupRequestId) cleanupInspecting.value = false
    }
  }

  const openCleanupDialog = (record: SandpackageInstallRow): void => {
    if (pluginOperationBusy.value || !canCleanupLocal(record)) return
    resetCleanupDialog()
    cleanupTargetApp.value = record.app
    cleanupTargetTitle.value = record.title
    cleanupTargetVersion.value = record.version
    cleanupTargetPending.value = record.cleanup_pending === true
    cleanupRangeLocked.value = cleanupTargetPending.value
    cleanupPackageVersion.value =
      repositoryCatalog.value?.plugins.find((item) => item.app === record.app)?.versions[0]
        ?.version ?? ''
    cleanupVisible.value = true
    inspectCleanup()
  }

  const prepareCleanupPackage = async (): Promise<void> => {
    const selected = cleanupRepositoryVersions.value.find(
      (item) => item.version === cleanupPackageVersion.value
    )
    if (
      !selected ||
      cleanupRangeLocked.value ||
      cleanupPackageLoading.value ||
      pluginOperationBusy.value
    ) {
      return
    }
    const app = cleanupTargetApp.value
    const requestId = ++cleanupRequestId
    cleanupPackageLoading.value = true
    cleanupError.value = ''
    try {
      const result = await sandpackageApi.prepareCleanupPackage({
        app,
        version: selected.version,
        sha256: selected.sha256
      })
      if (
        requestId !== cleanupRequestId ||
        !cleanupVisible.value ||
        result.app !== app ||
        result.version !== selected.version
      ) {
        throw new Error('补充清理包与当前选择不一致，请重新选择')
      }
      cleanupPackageLoading.value = false
      await inspectCleanup()
      if (cleanupVisible.value && cleanupInspection.value) {
        ElMessage.success('已使用所选版本重新检查清理范围')
      }
    } catch (error: unknown) {
      if (requestId === cleanupRequestId && cleanupVisible.value) {
        cleanupError.value = cleanupErrorMessage(error, '补充清理范围检查失败，请重试')
      }
    } finally {
      if (requestId === cleanupRequestId) cleanupPackageLoading.value = false
    }
  }

  const submitCleanup = async (): Promise<void> => {
    const inspection = cleanupInspection.value
    if (!inspection || !canSubmitCleanup.value || pluginOperationBusy.value) return
    const app = inspection.app
    const fingerprint = inspection.fingerprint
    const requestId = ++cleanupRequestId
    cleanupSubmitting.value = true
    cleanupRangeLocked.value = true
    cleanupError.value = ''
    try {
      const result = await sandpackageApi.cleanupApp({
        appName: app,
        fingerprint,
        confirmApp: cleanupConfirmation.value
      })
      if (requestId !== cleanupRequestId || !cleanupVisible.value) return
      if (result.app !== app || result.state !== 0) {
        throw new Error('清理结果与当前插件不一致，请重新检查')
      }
      cleanupCompleted.value = result
      cleanupInspection.value = null
      cleanupConfirmation.value = ''
      await Promise.all([getList(), fetchRepositoryCatalog()])
      if (requestId !== cleanupRequestId || !cleanupVisible.value) return
      ElMessage.success('插件残留已清理，可以重新安装')
    } catch (error: unknown) {
      if (requestId !== cleanupRequestId || !cleanupVisible.value) return
      cleanupInspection.value = null
      cleanupConfirmation.value = ''
      cleanupError.value = `${cleanupErrorMessage(error, '清理未完成')}，请重新检查后再试`
      await getList()
      if (requestId !== cleanupRequestId || !cleanupVisible.value) return
      const refreshed = installList.value.find((row) => row.app === app)
      if (refreshed?.cleanup_pending === true) cleanupTargetPending.value = true
    } finally {
      if (requestId === cleanupRequestId) cleanupSubmitting.value = false
    }
  }

  const goToRepositoryAfterCleanup = (): void => {
    cleanupVisible.value = false
    activeTab.value = 'repository'
  }

  const reloadCleanupBackend = async (): Promise<void> => {
    const completed = cleanupCompleted.value
    if (!completed?.restart_required || cleanupReloading.value) return
    cleanupReloading.value = true
    cleanupReloadError.value = ''
    try {
      await sandpackageApi.reloadBackend()
      cleanupCompleted.value = { ...completed, restart_required: false }
      ElMessage.success('后端服务已重载，现在可重新安装插件')
    } catch (error: unknown) {
      cleanupReloadError.value = cleanupErrorMessage(error, '后端服务重载失败，请重试')
    } finally {
      cleanupReloading.value = false
    }
  }

  const localActionReason = (record: SandpackageInstallRow): string => {
    if (record.state === 1) return '已安装'
    if (record.state === 7) return '已找到安装记录，但未找到插件文件。'
    if (record.state === 5) return '安装目录正被占用，请稍后重试。'
    if (record.state === 6) return '已找到插件文件，尚未完成登记。'
    if (record.state === 8) return '上次操作未完成，请查看详情。'
    return '当前状态需要处理，请查看详情。'
  }

  const localDetailReason = (record: SandpackageInstallRow): string => {
    if (record.cleanup_pending === true) {
      return record.recovery_reason || '上次清理尚未完成，请继续清理。'
    }
    if (record.state === 7) return '已找到安装记录，但未找到插件文件。'
    if (isLegacyFailedUpgradeRecovery(record)) return recoveryReason(record)
    return (
      record.recovery_reason || record.last_error || record.stage_label || localActionReason(record)
    )
  }

  const handleInstall = async (record: SandpackageInstallRow) => {
    if (pluginOperationBusy.value || !canInstallLocal(record)) return
    if (rejectOrdinaryAction(record)) return
    // 检查
    if (version.value?.sandpackage_version?.state === 'fail') {
      ElMessage.error('SandPackage 安装器版本检测失败')
      return
    }

    // 检查版本兼容性
    if (!checkVersionCompatibility(record.support, version.value?.sandadmin_version?.describe)) {
      ElMessage.error(
        `此插件仅支持 ${record.support} 版本框架，当前框架版本为 ${version.value?.sandadmin_version?.describe}，不兼容无法安装`
      )
      return
    }
    if (!acquireLocalWrite('install')) return

    try {
      const result = await sandpackageApi.installApp({ appName: record.app })
      if (result.state === 1) {
        ElMessage.success('安装成功')
      } else if (result.state === 4) {
        ElMessage.info('文件已部署，等待完成依赖安装')
      } else {
        ElMessage.warning(result.last_error || result.stage_label || '安装未完成，请查看插件状态')
      }
    } catch {
      // Error already handled by http utility
    } finally {
      await getList()
      releaseLocalWrite('install')
    }
  }

  const handleUninstall = async (record: SandpackageInstallRow) => {
    if (pluginOperationBusy.value || !canUninstallLocal(record)) return
    if (rejectOrdinaryAction(record)) return
    if (!acquireLocalWrite('uninstall')) return
    try {
      await sandpackageApi.uninstallApp({ appName: record.app })
      ElMessage.success('卸载成功')
    } catch {
      // Error already handled by http utility
    } finally {
      await getList()
      releaseLocalWrite('uninstall')
    }
  }

  const parseStrictSemver = (value: string | undefined): RegExpMatchArray | null => {
    if (!value) return null
    const match = value.match(
      /^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/
    )
    if (!match) return null
    const prerelease = match[4]
    return prerelease &&
      prerelease.split('.').some((part) => /^\d+$/.test(part) && !/^(0|[1-9]\d*)$/.test(part))
      ? null
      : match
  }

  const isStrictSemver = (value: string | undefined): boolean => parseStrictSemver(value) !== null

  const compareNumericIdentifier = (left: string, right: string): number => {
    if (left.length !== right.length) return left.length > right.length ? 1 : -1
    return left === right ? 0 : left > right ? 1 : -1
  }

  const compareStrictSemver = (left: string, right: string): number | null => {
    const leftParts = parseStrictSemver(left)
    const rightParts = parseStrictSemver(right)
    if (!leftParts || !rightParts) return null
    for (const index of [1, 2, 3]) {
      const result = compareNumericIdentifier(leftParts[index], rightParts[index])
      if (result !== 0) return result
    }
    const leftPre = leftParts[4]
    const rightPre = rightParts[4]
    if (!leftPre || !rightPre) return leftPre ? -1 : rightPre ? 1 : 0
    const leftItems = leftPre.split('.')
    const rightItems = rightPre.split('.')
    for (let index = 0; index < Math.min(leftItems.length, rightItems.length); index += 1) {
      const leftItem = leftItems[index]
      const rightItem = rightItems[index]
      if (leftItem === rightItem) continue
      const leftNumeric = /^\d+$/.test(leftItem)
      const rightNumeric = /^\d+$/.test(rightItem)
      if (leftNumeric && rightNumeric) return compareNumericIdentifier(leftItem, rightItem)
      if (leftNumeric) return -1
      if (rightNumeric) return 1
      return leftItem > rightItem ? 1 : -1
    }
    return leftItems.length === rightItems.length
      ? 0
      : leftItems.length > rightItems.length
        ? 1
        : -1
  }

  const hasCurrentAppBackup = (record: SandpackageInstallRow): boolean => {
    const backupId = record.package_backup_id ?? ''
    return (
      record.app !== '' &&
      backupId.startsWith(`${record.app}-package-`) &&
      new RegExp(
        `^${record.app.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}-package-\\d{14}-[a-f0-9]{12}$`
      ).test(backupId)
    )
  }

  const isReadyUpgradeCandidate = (record: SandpackageInstallRow): boolean => {
    if (
      !isUpgradeCandidateStage(record) ||
      !isStrictSemver(record.version) ||
      !isStrictSemver(record.upgrade_from_version) ||
      record.upgrade_from_version === undefined ||
      compareStrictSemver(record.version, record.upgrade_from_version) !== 1
    ) {
      return false
    }
    if (isPostgresqlLifecycleRecord(record)) {
      return record.ordinary_actions_blocked !== true
    }
    return (
      record.upgrade_candidate_verified === true &&
      hasCurrentAppBackup(record) &&
      /^[a-f0-9]{64}$/.test(record.registration_manifest ?? '') &&
      /^[a-f0-9]{64}$/.test(record.runtime_manifest ?? '')
    )
  }

  const isLegacyRecoverableCandidate = (record: SandpackageInstallRow): boolean => {
    return (
      !isPostgresqlLifecycleRecord(record) &&
      isUpgradeCandidateStage(record) &&
      record.legacy_recoverable === true &&
      hasCurrentAppBackup(record) &&
      isStrictSemver(record.version) &&
      isStrictSemver(record.derived_upgrade_from_version) &&
      record.derived_upgrade_from_version !== undefined &&
      compareStrictSemver(record.version, record.derived_upgrade_from_version) === 1
    )
  }

  const isUpgradeCandidateStage = (record: SandpackageInstallRow): boolean => {
    return (
      record.state === 2 &&
      record.update === 1 &&
      (isPostgresqlLifecycleRecord(record) || record.stage === 'ready')
    )
  }

  const isCompatibleUpgradeCandidate = (record: SandpackageInstallRow): boolean => {
    return (
      isReadyUpgradeCandidate(record) &&
      checkVersionCompatibility(record.support, version.value?.sandadmin_version?.describe)
    )
  }

  const handleUpgradeCandidate = async (record: SandpackageInstallRow) => {
    if (pluginOperationBusy.value) return
    if (rejectOrdinaryAction(record)) return
    if (!checkVersionCompatibility(record.support, version.value?.sandadmin_version?.describe)) {
      ElMessage.error(
        isPostgresqlLifecycleRecord(record)
          ? '升级包与当前宿主版本不兼容，不能升级'
          : '升级包与当前宿主版本不兼容，不能升级；如无需升级可撤回升级包'
      )
      return
    }
    const expected = `UPGRADE ${record.app}@${record.upgrade_from_version}->${record.version}`
    let confirmation = ''
    try {
      const result = await ElMessageBox.prompt(
        '系统将仅执行升级脚本 update.sql；不会执行 install.sql。请输入下方确认内容。',
        '确认升级包',
        {
          confirmButtonText: '确认升级',
          cancelButtonText: '取消',
          inputPlaceholder: expected,
          inputValidator: (value: string) => value === expected || '确认内容不匹配'
        }
      )
      confirmation = result.value
    } catch {
      return
    }
    if (!acquireLocalWrite('upgrade')) return
    try {
      await sandpackageApi.installApp({ appName: record.app, confirmation })
      ElMessage.success('升级已提交执行')
    } catch {
      // Error already handled by http utility
    } finally {
      await getList()
      releaseLocalWrite('upgrade')
    }
  }

  const handleDiscardCandidate = async (record: SandpackageInstallRow) => {
    if (pluginOperationBusy.value) return
    if (isPostgresqlLifecycleRecord(record)) return
    if (rejectOrdinaryAction(record)) return
    const expected = `DISCARD ${record.app}@${record.version}`
    let confirmation = ''
    try {
      const result = await ElMessageBox.prompt(
        '系统将仅恢复已验证的旧插件注册包并隔离当前升级包；不会执行数据库回滚，因为升级尚未执行。请输入下方确认内容。',
        '撤回升级包',
        {
          confirmButtonText: '撤回升级包',
          cancelButtonText: '取消',
          inputPlaceholder: expected,
          inputValidator: (value: string) => value === expected || '确认内容不匹配'
        }
      )
      confirmation = result.value
    } catch {
      return
    }
    if (!acquireLocalWrite('discard')) return
    try {
      await sandpackageApi.discardCandidate({
        appName: record.app,
        confirmation
      })
      ElMessage.success('升级包已撤回，旧插件注册包已恢复；数据库未执行无需回滚')
    } catch {
      // Error already handled by http utility
    } finally {
      await getList()
      releaseLocalWrite('discard')
    }
  }

  const handleRegisterExisting = async (record: SandpackageInstallRow) => {
    if (pluginOperationBusy.value) return
    if (rejectOrdinaryAction(record)) return
    const expected = `REGISTER ${record.app}@${record.version}`
    let confirmation = ''
    try {
      const result = await ElMessageBox.prompt(
        '系统将核对安装包、运行插件元数据，并逐文件校验后端和前端内容；不会复制文件、执行数据库脚本或运行插件代码。请输入下方确认内容。',
        '登记现有插件',
        {
          confirmButtonText: '核验并登记',
          cancelButtonText: '取消',
          inputPlaceholder: expected,
          inputValidator: (value: string) => value === expected || '确认内容不匹配'
        }
      )
      confirmation = result.value
    } catch {
      return
    }

    if (!acquireLocalWrite('register')) return
    try {
      await sandpackageApi.registerExisting({
        appName: record.app,
        confirmation
      })
      ElMessage.success('插件登记完成')
    } catch {
      // Error already handled by http utility
    } finally {
      await getList()
      releaseLocalWrite('register')
    }
  }

  const stateText = (record: SandpackageInstallRow): string => {
    if (record.cleanup_pending === true) return record.state_text || '清理未完成'
    if (isLegacyFailedUpgradeRecovery(record)) return '升级未完成'
    if (record.state === 7) return '安装文件缺失'
    if (record.state_text) return record.state_text
    return (
      {
        0: '未安装',
        1: '已安装',
        2: '等待安装',
        3: '等待处理冲突',
        4: '等待依赖安装',
        5: '安装目录被占用',
        6: '发现未登记部署',
        7: '安装文件缺失',
        8: '操作未完成'
      }[record.state] || '状态未知'
    )
  }

  const stateTagType = (
    record: SandpackageInstallRow
  ): 'success' | 'warning' | 'danger' | 'primary' | 'info' => {
    if (record.state === 1) return 'success'
    if (record.state === 2 || record.state === 6) return 'primary'
    if (record.state === 3 || record.state === 4) return 'warning'
    if (record.state === 0 || record.state === 5 || record.state === 7 || record.state === 8)
      return 'danger'
    return 'info'
  }

  const handleExecFront = (record: SandpackageInstallRow) => {
    if (pluginOperationBusy.value) return
    if (rejectOrdinaryAction(record)) return
    const extend = 'module-install:' + record.app
    terminalLaunchPending.value = true
    terminalRef.value?.open()
    setTimeout(() => {
      try {
        terminalRef.value?.frontInstall(extend)
      } finally {
        terminalLaunchPending.value = false
      }
    }, 500)
  }

  const handleExecBackend = (record: SandpackageInstallRow) => {
    if (pluginOperationBusy.value) return
    if (rejectOrdinaryAction(record)) return
    const extend = 'module-install:' + record.app
    terminalLaunchPending.value = true
    terminalRef.value?.open()
    setTimeout(() => {
      try {
        terminalRef.value?.backendInstall(extend)
      } finally {
        terminalLaunchPending.value = false
      }
    }, 500)
  }

  const handleTerminal = () => {
    if (pluginOperationBusy.value) return
    terminalRef.value?.open()
  }

  const columns: ColumnOption[] = [
    { prop: 'app', label: '插件标识', width: 120, useSlot: true },
    { prop: 'title', label: '插件名称', width: 150 },
    { prop: 'about', label: '插件描述', showOverflowTooltip: true },
    { prop: 'author', label: '作者', width: 120 },
    { prop: 'version', label: '版本', width: 100 },
    { prop: 'support', label: '框架兼容', width: 120, align: 'center' },
    { prop: 'state', label: '插件状态', width: 100, useSlot: true },
    { prop: 'npm', label: '前端依赖', width: 100, useSlot: true },
    { prop: 'composer', label: '后端依赖', width: 100, useSlot: true },
    {
      prop: 'operation',
      label: '操作',
      width: 220,
      fixed: 'right',
      useSlot: true
    }
  ]

  async function getList(preserveRecoverySession = false): Promise<void> {
    loading.value = true
    listError.value = ''
    if (!preserveRecoverySession) lockAllRecoveryWrites()
    try {
      const resp = await sandpackageApi.getAppList()
      installList.value = resp?.data || []
      version.value = resp?.version || {}
      syncFailedUpgradeSessions(installList.value)
    } catch (error: unknown) {
      installList.value = []
      for (const app of Object.keys(recoverySessions)) {
        delete recoverySessions[app]
      }
      recoveryFiles.clear()
      listError.value = readRecoveryErrorMessage(error, FAILED_UPGRADE_LIST_ERROR_MESSAGE)
    } finally {
      loading.value = false
    }
  }

  // ========== 插件仓库相关 ==========
  const repositoryCatalog = ref<RepositoryCatalog | null>(null)
  const repositoryLoading = ref(false)
  const repositoryLoaded = ref(false)
  const repositoryError = ref('')
  const repositoryKeyword = ref('')
  const repositoryVersionVisible = ref(false)
  const currentRepositoryPlugin = ref<RepositoryPlugin | null>(null)
  const downloadingKey = ref('')
  const repositoryActionError = ref('')
  const repositoryDocumentVisible = ref(false)
  const repositoryDocumentLoading = ref(false)
  const repositoryDocumentError = ref('')
  const repositoryDocumentMarkdown = ref('')
  const repositoryDocumentTarget = ref<RepositoryDocumentTarget | null>(null)
  let repositoryRequestId = 0
  let repositoryDocumentRequestId = 0

  const repositoryPlugins = computed(() => repositoryCatalog.value?.plugins ?? [])
  const cleanupRepositoryVersions = computed(
    () =>
      repositoryPlugins.value.find((item) => item.app === cleanupTargetApp.value)?.versions ?? []
  )
  const filteredRepositoryPlugins = computed(() => {
    const keyword = repositoryKeyword.value.trim().toLocaleLowerCase()
    if (!keyword) return repositoryPlugins.value
    return repositoryPlugins.value.filter((item) =>
      [item.app, item.title, item.about, item.author].some((value) =>
        value.toLocaleLowerCase().includes(keyword)
      )
    )
  })
  const repositoryDownloading = computed(() => downloadingKey.value !== '')
  const localWriteBusy = computed(() => localWriteOwner.value !== '')
  const recoveryOperationBusy = computed(() =>
    Object.values(recoverySessions).some(
      (session) => session !== undefined && isRecoveryWriteBusy(session.phase)
    )
  )
  const terminalOperationBusy = computed(
    () =>
      terminalLaunchPending.value ||
      terminalStore.taskList.some(
        (task) =>
          task.status === TaskStatus.WAITING ||
          task.status === TaskStatus.CONNECTING ||
          task.status === TaskStatus.RUNNING
      )
  )
  const cleanupOperationBusy = computed(
    () =>
      cleanupInspecting.value ||
      cleanupSubmitting.value ||
      cleanupPackageLoading.value ||
      cleanupReloading.value
  )
  const pluginOperationBusy = computed(
    () =>
      repositoryDownloading.value ||
      localWriteBusy.value ||
      recoveryOperationBusy.value ||
      terminalOperationBusy.value ||
      cleanupOperationBusy.value
  )
  const repositoryWritesBlocked = computed(
    () => hideGlobalPluginWrites.value || pluginOperationBusy.value
  )
  const repositoryDocumentTitle = computed(() => {
    const target = repositoryDocumentTarget.value
    return target ? `${target.title} v${target.version}` : '插件文档'
  })

  const fetchRepositoryCatalog = async (): Promise<void> => {
    const requestId = ++repositoryRequestId
    repositoryLoading.value = true
    repositoryError.value = ''
    try {
      const response = await sandpackageApi.getRepositoryCatalog()
      if (requestId !== repositoryRequestId) return
      repositoryCatalog.value = response
      repositoryLoaded.value = true
    } catch (error: unknown) {
      if (requestId !== repositoryRequestId) return
      repositoryCatalog.value = null
      repositoryLoaded.value = true
      repositoryError.value = readRecoveryErrorMessage(error, '插件仓库读取失败，请稍后重试')
    } finally {
      if (requestId === repositoryRequestId) repositoryLoading.value = false
    }
  }

  const showRepositoryVersions = (item: RepositoryPlugin): void => {
    currentRepositoryPlugin.value = item
    repositoryActionError.value = ''
    repositoryVersionVisible.value = true
  }

  const repositoryVersionKey = (
    plugin: RepositoryPlugin | null,
    item: RepositoryPluginVersion
  ): string => (plugin ? `${plugin.app}@${item.version}` : '')

  const repositoryLocalLabel = (local: RepositoryPluginLocal): string => {
    if (local.state === 0) return '未安装'
    if (local.state === 1) return `已安装 ${local.installed_version || local.version || ''}`.trim()
    if (local.state === 2) return '已有待处理安装包'
    if (local.state === 7) return '安装文件缺失'
    return '需要管理'
  }

  const repositoryLocalTagType = (
    local: RepositoryPluginLocal
  ): 'success' | 'warning' | 'danger' | 'info' => {
    if (local.state === 1 && !local.blocked) return 'success'
    if (local.state === 0 && !local.blocked) return 'info'
    return local.blocked ? 'danger' : 'warning'
  }

  const repositoryActionLabel = (action: RepositoryVersionAction): string =>
    ({
      install: '直接安装',
      upgrade: '直接升级',
      installed: '当前已安装',
      downgrade: '不支持降级',
      manage: '去插件管理',
      incompatible: '版本不兼容'
    })[action]

  const repositoryActionType = (
    action: RepositoryVersionAction
  ): 'primary' | 'warning' | 'info' => {
    if (action === 'install' || action === 'upgrade') return 'primary'
    return action === 'manage' ? 'warning' : 'info'
  }

  const repositoryActionDisabled = (
    plugin: RepositoryPlugin | null,
    item: RepositoryPluginVersion
  ): boolean => {
    if (item.action === 'manage') return pluginOperationBusy.value
    if (item.action !== 'install' && item.action !== 'upgrade') return true
    return !plugin || plugin.local.blocked || repositoryWritesBlocked.value
  }

  const goToPluginManagement = (): void => {
    repositoryVersionVisible.value = false
    activeTab.value = 'local'
  }

  const readRepositoryError = (error: unknown, fallback: string): string => {
    if (error instanceof Error && error.message.trim() !== '') return error.message
    return fallback
  }

  const findRepositorySelection = (
    app: string,
    version: string
  ): { plugin: RepositoryPlugin; item: RepositoryPluginVersion } | null => {
    const plugin = repositoryCatalog.value?.plugins.find((candidate) => candidate.app === app)
    const item = plugin?.versions.find((candidate) => candidate.version === version)
    return plugin && item ? { plugin, item } : null
  }

  const validatePreparedCandidate = (
    prepared: SandpackageInstallRow,
    app: string,
    targetVersion: string,
    action: 'install' | 'upgrade',
    fromVersion: string
  ): void => {
    if (
      prepared.app !== app ||
      prepared.version !== targetVersion ||
      prepared.state !== 2 ||
      prepared.ordinary_actions_blocked === true
    ) {
      throw new Error('下载的插件包与所选版本或当前状态不一致，请到插件管理查看')
    }
    if (
      action === 'upgrade' &&
      (prepared.update !== 1 || prepared.upgrade_from_version !== fromVersion)
    ) {
      throw new Error('升级包的来源版本与当前已安装版本不一致，请刷新后重试')
    }
    if (
      action === 'install' &&
      (prepared.update === 1 || (prepared.upgrade_from_version ?? '') !== '')
    ) {
      throw new Error('安装包被识别为升级包，已停止后续安装')
    }
  }

  const handleRepositoryVersionAction = async (
    plugin: RepositoryPlugin | null,
    selectedItem: RepositoryPluginVersion
  ): Promise<void> => {
    if (!plugin) return
    if (selectedItem.action === 'manage') {
      if (pluginOperationBusy.value) return
      goToPluginManagement()
      return
    }
    if (
      (selectedItem.action !== 'install' && selectedItem.action !== 'upgrade') ||
      pluginOperationBusy.value ||
      hideGlobalPluginWrites.value
    ) {
      return
    }

    const selectedAction = selectedItem.action
    const selectedSha256 = selectedItem.sha256
    const key = repositoryVersionKey(plugin, selectedItem)
    downloadingKey.value = key
    repositoryActionError.value = ''
    try {
      await Promise.all([getList(), fetchRepositoryCatalog()])
      if (listError.value !== '' || repositoryError.value !== '') {
        throw new Error('插件状态刷新失败，未执行下载或安装')
      }
      if (hideGlobalPluginWrites.value) {
        throw new Error('当前存在待恢复或无法确认的本地状态，请先到插件管理处理')
      }

      const refreshed = findRepositorySelection(plugin.app, selectedItem.version)
      if (
        !refreshed ||
        refreshed.item.sha256 !== selectedSha256 ||
        refreshed.item.action !== selectedAction
      ) {
        throw new Error('仓库版本或可执行动作已变化，请重新选择')
      }
      currentRepositoryPlugin.value = refreshed.plugin
      if (refreshed.plugin.local.blocked) {
        throw new Error(refreshed.plugin.local.reason || '本地插件状态异常，请到插件管理处理')
      }
      const refreshedLocalRow = installList.value.find((row) => row.app === refreshed.plugin.app)
      if (refreshedLocalRow?.ordinary_actions_blocked === true) {
        throw new Error(
          refreshedLocalRow.recovery_reason ||
            refreshedLocalRow.last_error ||
            '本地插件状态禁止常规安装或升级，请到插件管理处理'
        )
      }

      const fromVersion =
        selectedAction === 'upgrade' ? refreshed.plugin.local.installed_version || '' : ''
      if (selectedAction === 'upgrade' && fromVersion === '') {
        throw new Error('无法确认当前已安装版本，未执行升级')
      }
      if (
        selectedAction === 'upgrade' &&
        (refreshedLocalRow?.state !== 1 || refreshedLocalRow.version !== fromVersion)
      ) {
        throw new Error('本地插件列表与仓库识别的已安装版本不一致，未执行升级')
      }

      try {
        await ElMessageBox.confirm(
          selectedAction === 'upgrade'
            ? `确认将 ${refreshed.plugin.title} 从 ${fromVersion} 升级到 ${refreshed.item.version}？`
            : `确认安装 ${refreshed.plugin.title} v${refreshed.item.version}？`,
          selectedAction === 'upgrade' ? '确认直接升级' : '确认直接安装',
          {
            confirmButtonText: selectedAction === 'upgrade' ? '确认升级' : '确认安装',
            cancelButtonText: '取消',
            type: 'warning'
          }
        )
      } catch {
        return
      }

      const prepared = await sandpackageApi.downloadRepositoryPlugin({
        app: refreshed.plugin.app,
        version: refreshed.item.version,
        sha256: refreshed.item.sha256
      })
      validatePreparedCandidate(
        prepared,
        refreshed.plugin.app,
        refreshed.item.version,
        selectedAction,
        fromVersion
      )

      await sandpackageApi.installApp({
        appName: refreshed.plugin.app,
        confirmation:
          selectedAction === 'upgrade'
            ? `UPGRADE ${refreshed.plugin.app}@${fromVersion}->${refreshed.item.version}`
            : undefined
      })
      await Promise.all([getList(), fetchRepositoryCatalog()])

      const installed = installList.value.find((row) => row.app === refreshed.plugin.app)
      const refreshedAfterInstall = findRepositorySelection(
        refreshed.plugin.app,
        refreshed.item.version
      )
      if (
        installed?.state === 1 &&
        installed.version === refreshed.item.version &&
        installed.ordinary_actions_blocked !== true &&
        refreshedAfterInstall?.plugin.local.state === 1 &&
        refreshedAfterInstall.plugin.local.installed_version === refreshed.item.version
      ) {
        repositoryVersionVisible.value = false
        currentRepositoryPlugin.value = null
        ElMessage.success(selectedAction === 'upgrade' ? '插件升级成功' : '插件安装成功')
        return
      }

      const pendingReason =
        installed?.recovery_reason ||
        installed?.last_error ||
        installed?.stage_label ||
        refreshedAfterInstall?.plugin.local.reason ||
        '操作已提交，但插件尚未进入已安装状态，请到插件管理继续处理'
      repositoryActionError.value = pendingReason
      ElMessage.warning('插件尚未确认安装完成，请到插件管理查看')
    } catch (error: unknown) {
      repositoryActionError.value = readRepositoryError(error, '插件操作未完成，请到插件管理查看')
    } finally {
      downloadingKey.value = ''
    }
  }

  const loadRepositoryDocument = async (target: RepositoryDocumentTarget): Promise<void> => {
    const requestId = ++repositoryDocumentRequestId
    repositoryDocumentLoading.value = true
    repositoryDocumentError.value = ''
    repositoryDocumentMarkdown.value = ''
    try {
      const response = await sandpackageApi.getRepositoryDocument({
        app: target.app,
        version: target.version,
        sha256: target.sha256
      })
      if (requestId !== repositoryDocumentRequestId) return
      if (response.app !== target.app || response.version !== target.version) {
        throw new Error('文档响应与所选插件版本不一致')
      }
      repositoryDocumentMarkdown.value = response.markdown.trim() === '' ? '' : response.markdown
    } catch (error: unknown) {
      if (requestId !== repositoryDocumentRequestId) return
      repositoryDocumentError.value = readRepositoryError(error, '插件文档读取失败，请稍后重试')
    } finally {
      if (requestId === repositoryDocumentRequestId) repositoryDocumentLoading.value = false
    }
  }

  const openRepositoryDocument = (
    plugin: RepositoryPlugin | null,
    item: RepositoryPluginVersion
  ): void => {
    if (!plugin) return
    const target: RepositoryDocumentTarget = {
      app: plugin.app,
      title: plugin.title,
      version: item.version,
      sha256: item.sha256
    }
    repositoryDocumentTarget.value = target
    repositoryDocumentVisible.value = true
    loadRepositoryDocument(target)
  }

  const retryRepositoryDocument = (): void => {
    if (repositoryDocumentTarget.value) loadRepositoryDocument(repositoryDocumentTarget.value)
  }

  const closeRepositoryDocument = (): void => {
    repositoryDocumentRequestId += 1
    repositoryDocumentLoading.value = false
    repositoryDocumentError.value = ''
    repositoryDocumentMarkdown.value = ''
    repositoryDocumentTarget.value = null
  }

  // 监听 tab 切换
  watch(activeTab, (val) => {
    if (val === 'repository' && !repositoryLoaded.value && !repositoryLoading.value) {
      fetchRepositoryCatalog()
    }
  })

  watch(cleanupRepositoryVersions, (versions) => {
    if (cleanupVisible.value && cleanupPackageVersion.value === '' && versions[0]) {
      cleanupPackageVersion.value = versions[0].version
    }
  })

  watch([localDetailVisible, selectedLocalRow], ([visible, row]) => {
    if (!visible) return
    if (!row || (localDetailRecoveryExpected.value && !isLegacyFailedUpgradeRecovery(row))) {
      closeLocalDetail()
    }
  })

  onMounted(() => {
    getList()
    fetchRepositoryCatalog()
  })
</script>

<style lang="scss" scoped>
  .sandpackage-page {
    box-sizing: border-box;
    min-height: var(--art-full-height, 100%);
    padding-bottom: 24px;
  }

  .sandpackage-page-card {
    width: 100%;
  }

  .sandpackage-page-card :deep(.el-card__body) {
    height: auto;
    overflow: visible;
  }

  .local-toolbar {
    display: flex;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .environment-info {
    margin-bottom: 16px;
  }

  .recovery-detail {
    margin-top: 20px;
  }

  .failed-upgrade-steps {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    margin: 12px 0 8px;
    font-size: 14px;
    color: var(--el-text-color-regular);
  }

  .failed-upgrade-steps .current {
    font-weight: 600;
    color: var(--el-color-primary);
  }

  .step-arrow {
    color: var(--el-text-color-secondary);
  }

  .failed-upgrade-meta,
  .failed-upgrade-op-hint,
  .failed-upgrade-upload-tip {
    font-size: 13px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }

  .failed-upgrade-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-start;
    margin-top: 16px;
  }

  .failed-upgrade-error-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
  }

  .cleanup-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 160px;
    color: var(--el-text-color-secondary);
  }

  .cleanup-error-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
  }

  .cleanup-section {
    margin-top: 18px;

    h4 {
      margin: 0 0 10px;
      font-size: 14px;
      color: var(--el-text-color-primary);
    }
  }

  .cleanup-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .cleanup-residue-details {
    border: 1px solid var(--el-border-color-lighter);
    border-radius: var(--el-border-radius-base);

    summary {
      padding: 10px 12px;
      font-size: 13px;
      color: var(--el-color-primary);
      cursor: pointer;
    }
  }

  .cleanup-residue-scroll {
    max-height: 220px;
    padding: 0 12px 12px;
    overflow: auto;
  }

  .cleanup-empty {
    margin: 0;
    font-size: 13px;
    color: var(--el-text-color-secondary);
  }

  .cleanup-package-state {
    min-height: 80px;
    margin-top: 12px;
  }

  .cleanup-package summary {
    font-size: 14px;
    font-weight: 600;
    color: var(--el-color-primary);
    cursor: pointer;
  }

  .cleanup-package[open] summary {
    margin-bottom: 10px;
  }

  .cleanup-package-action {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 10px;
    margin-top: 12px;
  }

  .cleanup-paths {
    padding-left: 20px;
    margin: 0;
    font-size: 13px;
    line-height: 1.7;
    color: var(--el-text-color-regular);
    overflow-wrap: anywhere;
  }

  .cleanup-confirmation {
    display: grid;
    gap: 8px;
    margin-top: 18px;
    font-size: 14px;
    color: var(--el-text-color-regular);
  }

  .repository-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    margin-bottom: 16px;
  }

  .repository-search {
    width: min(360px, 100%);
  }

  .repository-state {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 160px;
    color: var(--el-text-color-secondary);
  }

  .repository-error-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
  }

  .app-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(320px, 100%), 1fr));
    gap: 16px;
  }

  .repository-app-card {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px;
    background: var(--el-bg-color);
    border: 1px solid var(--el-border-color);
    border-radius: 8px;
    transition: all 0.3s ease;

    &:hover {
      box-shadow: var(--el-box-shadow-light);
      transform: translateY(-2px);
    }
  }

  .repository-card-header {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    min-width: 0;
  }

  .repository-plugin-icon {
    flex: 0 0 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    font-size: 22px;
    color: var(--el-color-primary);
    background: var(--el-fill-color-light);
    border-radius: 8px;
  }

  .repository-card-heading {
    flex: 1;
    min-width: 0;
  }

  .repository-card-title {
    display: -webkit-box;
    min-height: 48px;
    overflow: hidden;
    font-size: 16px;
    font-weight: 600;
    line-height: 24px;
    color: var(--el-text-color-primary);
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .repository-card-version {
    overflow: hidden;
    font-size: 12px;
    line-height: 20px;
    color: var(--el-text-color-secondary);
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .repository-card-about {
    display: -webkit-box;
    min-height: 40px;
    margin: 0;
    overflow: hidden;
    font-size: 13px;
    line-height: 20px;
    color: var(--el-text-color-regular);
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .repository-card-meta,
  .repository-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-width: 0;
  }

  .repository-card-meta {
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .repository-card-author {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .repository-card-meta :deep(.el-tag) {
    flex: 0 0 auto;
    margin-left: 12px;
  }

  .repository-card-secondary-actions {
    display: flex;
    gap: 4px;
    align-items: center;
    min-width: 0;
  }

  .repository-card-primary-action {
    flex: 0 0 auto;
    white-space: nowrap;
  }

  @media (width <= 768px) {
    .failed-upgrade-actions {
      width: 100%;
    }

    .failed-upgrade-steps {
      font-size: 13px;
    }

    .version-item {
      flex-direction: column;
      align-items: flex-start;
    }

    .cleanup-package-action {
      grid-template-columns: 1fr;
    }
  }

  @media (width <= 480px) {
    .repository-card-footer {
      flex-wrap: wrap;
      gap: 8px;
    }

    .repository-card-primary-action {
      order: -1;
      width: 100%;
    }

    .repository-card-secondary-actions {
      width: 100%;
    }
  }

  .version-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .version-item {
    display: flex;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
    padding: 12px;
    background: var(--el-fill-color-light);
    border-radius: 6px;
  }

  .version-info-row {
    display: flex;
    gap: 12px;
    align-items: center;
  }

  .version-name {
    font-weight: 600;
    color: var(--el-text-color-primary);
  }

  .version-compatibility {
    margin-top: 6px;
    font-size: 12px;
    color: var(--el-text-color-secondary);
  }

  .version-remark {
    flex: 1;
    font-size: 13px;
    color: var(--el-text-color-regular);
  }

  .version-action-reason {
    margin-top: 6px;
    font-size: 12px;
    line-height: 1.5;
    color: var(--el-text-color-secondary);
  }

  .repository-document {
    min-height: 160px;
    padding: 16px;
    margin: 0;
    overflow: auto;
    font-family: var(--el-font-family);
    font-size: 14px;
    line-height: 1.7;
    color: var(--el-text-color-primary);
    overflow-wrap: anywhere;
    white-space: pre-wrap;
    user-select: text;
    background: var(--el-fill-color-light);
    border: 1px solid var(--el-border-color);
    border-radius: 8px;
  }
</style>
