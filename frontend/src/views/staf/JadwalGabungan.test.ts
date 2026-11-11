import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, post } }
})
vi.mock('vue-router', () => ({ useRoute: () => ({ query: { tab: 'jadwal' } }), useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

import { useAuthStore } from '@/stores/auth'
import JadwalView from './JadwalView.vue'

describe('Jadwal ujian gabungan', () => {
  beforeEach(() => {
    get.mockReset().mockImplementation((url: string) => {
      if (url === '/mapel') {
        return Promise.resolve({ data: { data: [{ id: 1, kode: 'IPA7', nama: 'IPA Kelas 7' }, { id: 2, kode: 'MTK8', nama: 'Matematika Kelas 8' }] } })
      }
      if (url === '/paket-soal') {
        return Promise.resolve({ data: { data: [{ id: 10, mapel_id: 1, nama: 'Paket IPA' }, { id: 20, mapel_id: 2, nama: 'Paket MTK' }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    post.mockReset().mockResolvedValue({ data: { message: '2 jadwal ujian berhasil dibuat.' } })
  })

  it('mengirim satu permintaan gabungan berisi tiap mapel dan paketnya', async () => {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().setUser({ id: 1, username: 'op', nama: 'Operator', role: 'operator' })
    const wrapper = mount(JadwalView, { global: { plugins: [pinia] } })
    await flushPromises()

    await wrapper.findAll('button').find((b) => b.text().includes('Terbitkan Jadwal'))!.trigger('click')
    await wrapper.find('input[type="checkbox"]').setValue(true)
    const mapel = wrapper.findAll('select[aria-label^="Mapel"]')
    await mapel[1].setValue(2)
    await wrapper.find('input[placeholder^="Contoh: Ujian"]').setValue('UTS')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/jadwal/gabungan', expect.objectContaining({
      nama: 'UTS',
      butir: [
        { mapel_id: 1, paket_soal_id: 10 },
        { mapel_id: 2, paket_soal_id: 20 }
      ]
    }))
    wrapper.unmount()
  })
})
