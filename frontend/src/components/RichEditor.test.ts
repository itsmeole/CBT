import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import RichEditor from './RichEditor.vue'

async function pasang(modelValue = '') {
  const wrapper = mount(RichEditor, { props: { modelValue }, attachTo: document.body })
  await nextTick()
  await new Promise((resolve) => setTimeout(resolve, 0))
  return wrapper
}

function emitTerakhir(wrapper: Awaited<ReturnType<typeof pasang>>): string {
  const semua = wrapper.emitted('update:modelValue') ?? []
  return (semua[semua.length - 1]?.[0] as string) ?? ''
}

describe('RichEditor', () => {
  it('menampilkan konten awal', async () => {
    const wrapper = await pasang('<p>Hitung <strong>nilai</strong></p>')
    expect(wrapper.html()).toContain('Hitung')
    expect(wrapper.html()).toContain('<strong>nilai</strong>')
    wrapper.unmount()
  })

  it('menyisipkan gambar dan audio sebagai tag yang diizinkan BE', async () => {
    const wrapper = await pasang('<p>Soal</p>')
    const vm = wrapper.vm as unknown as { insertImage: (s: string) => void; insertAudio: (s: string) => void }
    vm.insertImage('/storage/soal/a.png')
    vm.insertAudio('/storage/soal/a.mp3')
    await nextTick()
    const html = emitTerakhir(wrapper)
    expect(html).toContain('<img')
    expect(html).toContain('src="/storage/soal/a.png"')
    expect(html).toContain('<audio')
    expect(html).toContain('src="/storage/soal/a.mp3"')
    wrapper.unmount()
  })

  it('menyisipkan rumus LaTeX sebagai teks berpembatas $ dan tabel', async () => {
    const wrapper = await pasang('<p>Soal</p>')
    await wrapper.findAll('button').find((b) => b.text().includes('Rumus'))!.trigger('click')
    await wrapper.find('input.font-mono').setValue('\\frac{a}{b}')
    await wrapper.findAll('button').find((b) => b.text() === 'Sisipkan')!.trigger('click')
    expect(emitTerakhir(wrapper)).toContain('$\\frac{a}{b}$')

    await wrapper.findAll('button').find((b) => b.text() === 'Tabel')!.trigger('click')
    expect(emitTerakhir(wrapper)).toContain('<table')
    wrapper.unmount()
  })

  it('mengembalikan string kosong untuk dokumen kosong', async () => {
    const wrapper = await pasang('<p>x</p>')
    await wrapper.setProps({ modelValue: '' })
    await nextTick()
    expect(wrapper.html()).not.toContain('>x<')
    wrapper.unmount()
  })
})
