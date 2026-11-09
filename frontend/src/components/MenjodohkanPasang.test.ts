import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import MenjodohkanPasang from './MenjodohkanPasang.vue'

const kiri = [
  { id: 'k1', konten: 'Jepang' },
  { id: 'k2', konten: 'Prancis' }
]
const kanan = [
  { id: 'r1', konten: 'Paris' },
  { id: 'r2', konten: 'Tokyo' }
]

function pasang(modelValue: Record<string, string | number> = {}, nonaktif = false) {
  return mount(MenjodohkanPasang, { props: { kiri, kanan, modelValue, nonaktif } })
}

const tombolKiri = (w: ReturnType<typeof pasang>) => w.findAll('ul[aria-label="Item kiri"] li > div > button:first-child')
const tombolKanan = (w: ReturnType<typeof pasang>) => w.findAll('ul[aria-label="Pilihan pasangan"] button')

describe('MenjodohkanPasang', () => {
  it('ketuk kiri lalu kanan membuat pasangan dan otomatis memilih item kiri berikutnya', async () => {
    const w = pasang()
    await tombolKiri(w)[0].trigger('click')
    await tombolKanan(w)[1].trigger('click')

    expect(w.emitted('update:modelValue')?.[0][0]).toEqual({ k1: 'r2' })
    expect(tombolKiri(w)[1].attributes('aria-pressed')).toBe('true')
  })

  it('ketuk kanan tanpa item kiri terpilih tidak membuat pasangan', async () => {
    const w = pasang()
    await tombolKanan(w)[0].trigger('click')
    expect(w.emitted('update:modelValue')).toBeUndefined()
  })

  it('item kanan yang sudah dipakai dipindahkan ke item kiri lain', async () => {
    const w = pasang({ k1: 'r1' })
    await tombolKiri(w)[1].trigger('click')
    await tombolKanan(w)[0].trigger('click')
    expect(w.emitted('update:modelValue')?.[0][0]).toEqual({ k2: 'r1' })
  })

  it('tombol lepas menghapus pasangan', async () => {
    const w = pasang({ k1: 'r1' })
    await w.find('button[aria-label="Lepas pasangan"]').trigger('click')
    expect(w.emitted('update:modelValue')?.[0][0]).toEqual({})
  })

  it('menampilkan jumlah pasangan dan tidak bisa diubah saat nonaktif', async () => {
    const w = pasang({ k1: 'r1' }, true)
    expect(w.find('[role="status"]').text()).toBe('1/2')
    await tombolKiri(w)[1].trigger('click')
    await tombolKanan(w)[1].trigger('click')
    expect(w.emitted('update:modelValue')).toBeUndefined()
  })
})
