import request from '@/utils/http'

export type SystemPackage = 'supdger/sand-core' | 'supdger/sand-package'
export interface UpdateTarget { package: SystemPackage; version: string }
export interface UpdateCheck { code: string; label: string; state: 'ok' | 'fail'; message: string }
export interface UpdateTask {
  id: string
  state: 'queued' | 'running' | 'succeeded' | 'failed' | 'recovering' | 'recovered' | 'recovery_required'
  stage: string
  targets: UpdateTarget[]
  created_at: number
  updated_at: number
  logs: { time: number; stage: string; message: string }[]
  error: string
  recovery_available: boolean
}
export interface UpdateStatus {
  installed: (UpdateTarget & { name: string })[]
  releases: (UpdateTarget & { name: string; notes: string })[]
  active_task: UpdateTask | null
  capabilities: { supported: boolean; reason: string }
  checks: UpdateCheck[]
}
export interface UpdateInspection {
  confirmation: string
  targets: UpdateTarget[]
  checks: UpdateCheck[]
  can_start: boolean
  expires_at: number
}
const url = '/app/sandpackage/systemUpdate'
export const systemUpdateApi = {
  status: () => request.get<UpdateStatus>({ url: `${url}/status`, timeout: 65000, showErrorMessage: false }),
  inspect: (targets: UpdateTarget[]) => request.post<UpdateInspection>({ url: `${url}/inspect`, data: { targets }, timeout: 135000, showErrorMessage: false }),
  start: (confirmation: string) => request.post<UpdateTask>({ url: `${url}/start`, data: { confirmation }, showErrorMessage: false }),
  task: (id: string) => request.get<UpdateTask>({ url: `${url}/task`, params: { id }, showErrorMessage: false }),
  recover: (id: string) => request.post<UpdateTask>({ url: `${url}/recover`, data: { id }, showErrorMessage: false })
}
