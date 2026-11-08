import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const { get, put, post, replace } = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
  replace: vi.fn()
}))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, put, post } }
})

vi.mock('vue-router', () => ({ useRouter: () => ({ replace }) }))

import { ApiError } from '@/api/client'
import { bersihkanSemua } from '@/lib/offlineQueue'
import PengerjaanUjianView from './PengerjaanUjianView.vue'

function responsGet(url: string) {
  if (url === '/time') return Promise.resolve({ data: { time: Date.now() } })
  if (url === '/ujian/state') {
    return Promise.resolve({
      data: { data: { status: 'mengerjakan', waktu_selesai_ms: Date.now() + 3_600_000, jawaban_tersimpan: {} } }
    })
  }
  if (url === '/ujian/soal') {
    return Promise.resolve({
      data: {
        data: [
          { nomor: 1, soal_id: 11, tipe: 'pg', konten: '<p>2+2?</p>', opsi: [{ id: 'a1', konten: 'tiga' }, { id: 'a2', konten: 'empat' }] }
        ]
      }
    })
  }
  return Promise.reject(new Error(`URL tak terduga: ${url}`))
}

function setVisibility(state: 'hidden' | 'visible') {
  Object.defineProperty(document, 'visibilityState', { value: state, configurable: true })
}

async function pasang(): Promise<VueWrapper> {
  const wrapper = mount(PengerjaanUjianView, { global: { plugins: [createPinia()] } })
  await flushPromises()
  return wrapper
}

describe('PengerjaanUjianView', () => {
  beforeEach(async () => {
    setActivePinia(createPinia())
    get.mockReset().mockImplementation(responsGet)
    put.mockReset().mockResolvedValue({ data: { status: 'saved' } })
    post.mockReset().mockResolvedValue({ data: {} })
    replace.mockReset()
    setVisibility('visible')
    await bersihkanSemua()
  })

  afterEach(() => {
    setVisibility('visible')
  })

  it('memuat soal dari kontrak BE dan menampilkan pilihan', async () => {
    const wrapper = await pasang()
    expect(wrapper.text()).toContain('2+2?')
    expect(wrapper.text()).toContain('empat')
    wrapper.unmount()
  })

  it('autosave mengirim payload items dengan soal_id, jawaban id opsi, dan client_seq', async () => {
    const wrapper = await pasang()
    await wrapper.findAll('div.cursor-pointer')[1].trigger('click')
    await vi.waitFor(() => expect(put).toHaveBeenCalledTimes(1), { timeout: 2000 })

    const [url, body] = put.mock.calls[0]
    expect(url).toBe('/ujian/jawaban')
    expect(body.items).toHaveLength(1)
    expect(body.items[0]).toMatchObject({ soal_id: 11, jawaban: 'a2' })
    expect(body.items[0].client_seq).toBeGreaterThan(0)
    await vi.waitFor(() => expect(wrapper.text()).toContain('Tersimpan'))
    wrapper.unmount()
  })

  it('saat jaringan putus tampil Offline, lalu terkirim ulang dan kembali Tersimpan', async () => {
    put.mockRejectedValueOnce(new ApiError('NETWORK_ERROR', 'Tidak ada koneksi', null))
    const wrapper = await pasang()
    await wrapper.findAll('div.cursor-pointer')[0].trigger('click')
    await vi.waitFor(() => expect(wrapper.text()).toContain('Tanpa koneksi'), { timeout: 2000 })

    window.dispatchEvent(new Event('online'))
    await vi.waitFor(() => expect(put).toHaveBeenCalledTimes(2), { timeout: 2000 })
    await vi.waitFor(() => expect(wrapper.text()).toContain('Tersimpan'))
    expect(put.mock.calls[1][1].items[0]).toMatchObject({ soal_id: 11, jawaban: 'a1' })
    wrapper.unmount()
  })

  it('meninggalkan halaman mengirim sinyal lock dan menampilkan overlay terkunci dengan timer tetap tampil', async () => {
    const wrapper = await pasang()
    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/ujian/lock', { pemicu: 'visibilitychange' }, expect.objectContaining({ fetchOptions: { keepalive: true } }))
    expect(wrapper.text()).toContain('Ujian terkunci, hubungi pengawas')
    expect(wrapper.find('[role="alertdialog"]').text()).toMatch(/\d{2}:\d{2}:\d{2}/)
    wrapper.unmount()
  })

  it('tidak mengirim lock berulang kali dan menolak perubahan jawaban saat terkunci', async () => {
    const wrapper = await pasang()
    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    window.dispatchEvent(new Event('blur'))
    await flushPromises()
    expect(post.mock.calls.filter(([url]) => url === '/ujian/lock')).toHaveLength(1)

    await wrapper.findAll('div.cursor-pointer')[1].trigger('click')
    await new Promise((resolve) => setTimeout(resolve, 600))
    expect(put).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('lock yang gagal karena offline diantrekan dan dikirim sebelum jawaban saat online', async () => {
    post.mockRejectedValueOnce(new ApiError('NETWORK_ERROR', 'Tidak ada koneksi', null))
    const wrapper = await pasang()
    setVisibility('hidden')
    document.dispatchEvent(new Event('visibilitychange'))
    await flushPromises()
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(1))

    window.dispatchEvent(new Event('online'))
    await vi.waitFor(() => expect(post).toHaveBeenCalledTimes(2), { timeout: 2000 })
    expect(post.mock.calls[1][0]).toBe('/ujian/lock')
    wrapper.unmount()
  })
})
