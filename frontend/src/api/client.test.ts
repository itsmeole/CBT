import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios'
import { describe, expect, it } from 'vitest'
import { ApiError, normalizeError } from './client'

function axiosErrorDenganRespons(status: number, data: unknown): AxiosError {
  const response = { status, data, statusText: '', headers: {}, config: { headers: new AxiosHeaders() } } as AxiosResponse
  return new AxiosError('gagal', 'ERR_BAD_REQUEST', undefined, undefined, response)
}

describe('normalizeError', () => {
  it('memakai kode dan pesan dari format error backend', () => {
    const err = normalizeError(
      axiosErrorDenganRespons(423, { error: { code: 'SESSION_LOCKED', message: 'Ujian terkunci, hubungi pengawas.' } })
    )
    expect(err).toBeInstanceOf(ApiError)
    expect(err.code).toBe('SESSION_LOCKED')
    expect(err.message).toBe('Ujian terkunci, hubungi pengawas.')
  })

  it('menurunkan kode dari status HTTP bila body tidak sesuai format', () => {
    expect(normalizeError(axiosErrorDenganRespons(403, {})).code).toBe('FORBIDDEN')
    expect(normalizeError(axiosErrorDenganRespons(429, {})).code).toBe('RATE_LIMITED')
  })

  it('menandai kegagalan jaringan (tanpa respons) sebagai NETWORK_ERROR berbahasa Indonesia', () => {
    const err = normalizeError(new AxiosError('Network Error', 'ERR_NETWORK'))
    expect(err.isNetworkError).toBe(true)
    expect(err.message).toContain('koneksi')
  })

  it('mengembalikan UNKNOWN_ERROR untuk error non-axios', () => {
    expect(normalizeError(new Error('x')).code).toBe('UNKNOWN_ERROR')
  })
})

