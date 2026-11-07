import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const { get, post, handlers, leave } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  handlers: {} as Record<string, (payload: unknown) => void>,
  leave: vi.fn()
}))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, post } }
})

vi.mock('@/lib/echo', () => {
  const channel = {
    listen(nama: string, cb: (payload: unknown) => void) {
      handlers[nama] = cb
      return channel
    }
  }
  return { getEcho: () => ({ private: () => channel, leave, connector: {} }) }
})

import { useAuthStore } from '@/stores/auth'
vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

import MonitoringPengawasView from './MonitoringPengawasView.vue'

const peserta = [
  { id: 1, user_id: 101, nama: 'Budi', nis: '001', kelas: 'X-1', status: 'mengerjakan', terjawab: 3, jumlah_kunci: 0, sisa_detik: 1200 },
  { id: 2, user_id: 102, nama: 'Citra', nis: '002', kelas: 'X-1', status: 'terkunci', terjawab: 5, jumlah_kunci: 3, sisa_detik: 900 },
  { id: 3, user_id: 103, nama: 'Ani', nis: '003', kelas: 'X-1', status: 'selesai', terjawab: 10, jumlah_kunci: 0, sisa_detik: 0 }
]

function pasang() {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().$patch({ user: { id: 9, username: 'op', nama: 'Operator', role: 'operator' } })
  return mount(MonitoringPengawasView, {
    global: { plugins: [pinia] }
  })
}

describe('MonitoringPengawasView', () => {
  beforeEach(() => {
    get.mockReset().mockImplementation((url: string) => {
      if (url === '/pengawasan/sesi') {
        return Promise.resolve({ data: { data: [{ id: 7, jadwal: 'UTS', mapel: 'IPA', ruang: 'Lab 1', tanggal: '2026-11-10', sesi_ke: 1 }] } })
      }
      if (url === '/lock-threshold') return Promise.resolve({ data: { data: { ambang_kunci: 3 } } })
      if (url === '/sesi-ruang/7/monitoring') {
        return Promise.resolve({ data: { data: { sesi: { id: 7, jadwal: 'UTS', mapel: 'IPA', ruang: 'Lab 1' }, peserta } } })
      }
      return Promise.reject(new Error(`URL tak terduga: ${url}`))
    })
    post.mockReset().mockResolvedValue({ data: {} })
    leave.mockReset()
    Object.keys(handlers).forEach((k) => delete handlers[k])
  })

  it('menampilkan siswa terkunci paling atas dan peringatan melebihi ambang', async () => {
    const wrapper = pasang()
    await flushPromises()

    const kartu = wrapper.findAll('li')
    expect(kartu).toHaveLength(3)
    expect(kartu[0].text()).toContain('Citra')
    expect(kartu[0].text()).toContain('Terkunci')
    expect(kartu[0].text()).toContain('melewati ambang')
    expect(kartu[0].text()).toContain('Buka Kunci')
    expect(kartu[2].text()).toContain('Ani')
    expect(wrapper.vm).toBeTruthy()
    wrapper.unmount()
  })

  it('event realtime mengunci siswa, menaikkan kartunya, dan memunculkan alert', async () => {
    const wrapper = pasang()
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)

    handlers['.PesertaLocked']({ peserta_id: 1, jumlah_kunci: 1, status: 'terkunci' })
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toContain('terkunci')
    expect(wrapper.findAll('li').map((li) => li.text()).filter((t) => t.includes('Terkunci'))).toHaveLength(2)
    wrapper.unmount()
  })

  it('buka kunci mewajibkan alasan minimal 5 karakter dan memiliki alasan cepat', async () => {
    const wrapper = pasang()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text() === 'Buka Kunci')!.trigger('click')

    const kirim = () => wrapper.findAll('button').filter((b) => b.text().includes('Buka Kunci')).at(-1)!
    expect(kirim().attributes('disabled')).toBeDefined()

    await wrapper.find('textarea').setValue('abc')
    expect(kirim().attributes('disabled')).toBeDefined()

    await wrapper.findAll('button').find((b) => b.text() === 'Panggilan masuk')!.trigger('click')
    expect(kirim().attributes('disabled')).toBeUndefined()
    await kirim().trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/peserta/2/unlock', { alasan: 'Panggilan masuk' })
    expect(wrapper.text()).toContain('Kunci Citra dibuka')
    wrapper.unmount()
  })

  it('reset login memanggil endpoint dengan ID akun siswa', async () => {
    const wrapper = pasang()
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text() === 'Reset Login')!.trigger('click')
    await wrapper.findAll('button').find((b) => b.text() === 'Ya, Reset')!.trigger('click')
    await flushPromises()

    // Kartu pertama (Citra, id 2) -> user_id 102
    expect(post).toHaveBeenCalledWith('/auth/reset-login/102')
    wrapper.unmount()
  })
})
