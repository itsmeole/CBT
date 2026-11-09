import axios, { AxiosError } from 'axios'
import type { ApiErrorBody } from '@/types/auth'
import { pesanError } from './errors'

/** Error aplikasi yang sudah dinormalisasi dari respons backend atau kegagalan jaringan. */
export class ApiError extends Error {
  readonly code: string
  readonly status: number | null
  readonly details?: Record<string, string[]>

  constructor(code: string, message: string, status: number | null, details?: Record<string, string[]>) {
    super(message)
    this.name = 'ApiError'
    this.code = code
    this.status = status
    this.details = details
  }

  get isNetworkError(): boolean {
    return this.code === 'NETWORK_ERROR'
  }
}

type UnauthenticatedHandler = () => void
let onUnauthenticated: UnauthenticatedHandler | null = null
let inMemoryToken: string | null = null

/** Didaftarkan oleh auth store agar klien API tidak bergantung langsung pada store (hindari circular import). */
export function setUnauthenticatedHandler(handler: UnauthenticatedHandler | null): void {
  onUnauthenticated = handler
}

/** Simpan Bearer token hanya di memori (RAM), TIDAK PERNAH di localStorage/sessionStorage (Rules FE). */
export function setAuthToken(token: string | null): void {
  inMemoryToken = token
}

/**
 * Satu-satunya klien HTTP aplikasi. Semua panggilan API wajib lewat sini (Rules FE bagian 7).
 * Autentikasi mendukung cookie Sanctum (HttpOnly) maupun Bearer token di memori.
 */
export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  timeout: 15_000,
  headers: { Accept: 'application/json' }
})

apiClient.interceptors.request.use((config) => {
  if (inMemoryToken) {
    config.headers.Authorization = `Bearer ${inMemoryToken}`
  }
  return config
})

export function normalizeError(error: unknown): ApiError {
  if (error instanceof ApiError) return error

  if (axios.isAxiosError(error)) {
    const axiosError = error as AxiosError<Partial<ApiErrorBody>>

    // Tidak ada respons: offline, timeout, atau server tidak terjangkau.
    if (!axiosError.response) {
      return new ApiError('NETWORK_ERROR', pesanError('NETWORK_ERROR'), null)
    }

    const { status, data } = axiosError.response
    const code = data?.error?.code ?? kodeDariStatus(status)
    // Pesan dari server dipakai bila ada, jika tidak pakai pesan baku bahasa Indonesia.
    // Untuk galat validasi, tampilkan alasan pertama yang spesifik agar pengguna tahu apa yang perlu diperbaiki.
    const alasan = code === 'VALIDATION_ERROR' ? Object.values(data?.error?.details ?? {}).flat()[0] : undefined
    const message = alasan ?? data?.error?.message ?? pesanError(code)
    return new ApiError(code, message, status, data?.error?.details)
  }

  return new ApiError('UNKNOWN_ERROR', pesanError('UNKNOWN_ERROR'), null)
}

function kodeDariStatus(status: number): string {
  switch (status) {
    case 401:
      return 'UNAUTHENTICATED'
    case 403:
      return 'FORBIDDEN'
    case 422:
      return 'VALIDATION_ERROR'
    case 429:
      return 'RATE_LIMITED'
    default:
      return 'UNKNOWN_ERROR'
  }
}

apiClient.interceptors.response.use(
  (response) => response,
  (error: unknown) => {
    const apiError = normalizeError(error)
    if (apiError.code === 'UNAUTHENTICATED' && onUnauthenticated) {
      onUnauthenticated()
    }
    return Promise.reject(apiError)
  }
)

/** Sanctum SPA memerlukan cookie CSRF sebelum login. Endpoint ini berada di root domain, bukan di /api/v1. */
export async function ensureCsrfCookie(): Promise<void> {
  const origin = new URL(import.meta.env.VITE_API_BASE_URL, window.location.origin).origin
  await apiClient.get(`${origin}/sanctum/csrf-cookie`)
}

