import { describe, expect, it } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import StafNavList from './StafNavList.vue'
import { buatMenu } from '@/lib/navigasi'

const Kosong = { render: () => null }

async function pasang(path: string, collapsed = false, role: 'operator' | 'guru' = 'operator') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: ['/staf', '/staf/master', '/staf/jadwal', '/staf/bank-soal', '/staf/kontrol-sesi', '/staf/monitoring', '/staf/koreksi-essay', '/staf/nilai', '/staf/analisis-butir', '/staf/berita-acara', '/staf/cetak', '/staf/audit-log'].map(
      (p) => ({ path: p, component: Kosong })
    )
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount(StafNavList, { props: { items: buatMenu(role), collapsed }, global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

const grup = (wrapper: ReturnType<typeof mount>, nama: string) => wrapper.findAll('button').find((b) => b.text().includes(nama))!

describe('StafNavList', () => {
  it('Master Data berupa dropdown yang otomatis terbuka di halamannya, dengan sub-menu aktif ditandai', async () => {
    const { wrapper } = await pasang('/staf/master?tab=guru')
    const tombol = grup(wrapper, 'Master Data')
    expect(tombol.attributes('aria-expanded')).toBe('true')

    const anak = wrapper.findAll('a').filter((a) => a.attributes('href')?.startsWith('/staf/master'))
    expect(anak.map((a) => a.text())).toEqual(['Siswa', 'Guru', 'Pengampu Mapel', 'Kelas', 'Mata Pelajaran', 'Ruang Ujian', 'Tahun Ajaran'])
    const aktif = anak.filter((a) => a.attributes('aria-current') === 'page')
    expect(aktif.map((a) => a.text())).toEqual(['Guru'])
    expect(anak[1].attributes('href')).toBe('/staf/master?tab=guru')
    wrapper.unmount()
  })

  it('dropdown tertutup di halaman lain dan dapat dibuka/ditutup manual', async () => {
    const { wrapper } = await pasang('/staf/bank-soal')
    const tombol = grup(wrapper, 'Master Data')
    expect(tombol.attributes('aria-expanded')).toBe('false')
    expect(wrapper.find('#submenu-staf-master').element.parentElement?.getAttribute('data-terbuka')).toBe('false')

    await tombol.trigger('click')
    expect(tombol.attributes('aria-expanded')).toBe('true')
    await tombol.trigger('click')
    expect(tombol.attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('Jadwal & Paket Ujian punya sub-opsi Jadwal, Paket Soal, dan Bobot Soal', async () => {
    const { wrapper } = await pasang('/staf/jadwal?tab=paket')
    const anak = wrapper.findAll('a').filter((a) => a.attributes('href')?.startsWith('/staf/jadwal'))
    expect(anak.map((a) => a.text())).toEqual(['Jadwal & Sesi Ruang', 'Paket Soal', 'Bobot Soal'])
    expect(anak.filter((a) => a.attributes('aria-current') === 'page').map((a) => a.text())).toEqual(['Paket Soal'])
    wrapper.unmount()
  })

  it('berpindah sub-menu mengganti tab aktif tanpa menutup grup', async () => {
    const { wrapper, router } = await pasang('/staf/master?tab=siswa')
    await wrapper.findAll('a').find((a) => a.text() === 'Kelas')!.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query.tab).toBe('kelas')
    const aktif = wrapper.findAll('a').filter((a) => a.attributes('aria-current') === 'page')
    expect(aktif.map((a) => a.text())).toEqual(['Kelas'])
    expect(grup(wrapper, 'Master Data').attributes('aria-expanded')).toBe('true')
    wrapper.unmount()
  })

  it('saat sidebar diciutkan grup menjadi satu ikon menuju sub-menu pertama', async () => {
    const { wrapper } = await pasang('/staf/bank-soal', true)
    expect(wrapper.findAll('button')).toHaveLength(0)
    const master = wrapper.find('a[title="Master Data"]')
    expect(master.attributes('href')).toBe('/staf/master?tab=siswa')
    wrapper.unmount()
  })

  it('guru tidak melihat Master Data tetapi melihat dropdown Jadwal & Paket Ujian', async () => {
    const { wrapper } = await pasang('/staf', false, 'guru')
    const tombol = wrapper.findAll('button').map((b) => b.text())
    expect(tombol.some((t) => t.includes('Master Data'))).toBe(false)
    expect(tombol.some((t) => t.includes('Jadwal & Paket Ujian'))).toBe(true)
    wrapper.unmount()
  })
})
