import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import KonfirmasiPasswordModal from './KonfirmasiPasswordModal.vue'

describe('KonfirmasiPasswordModal', () => {
  it('tombol nonaktif sebelum password diisi, lalu mengirim password saat dikonfirmasi', async () => {
    const w = mount(KonfirmasiPasswordModal, { props: { tombol: 'Unduh' } })
    expect(w.find('button[type="submit"]').attributes('disabled')).toBeDefined()

    await w.find('input').setValue('rahasia123')
    expect(w.find('button[type="submit"]').attributes('disabled')).toBeUndefined()

    await w.find('form').trigger('submit')
    expect(w.emitted('konfirmasi')?.[0]).toEqual(['rahasia123'])
  })

  it('menampilkan galat dan memancarkan batal', async () => {
    const w = mount(KonfirmasiPasswordModal, { props: { galat: 'Password salah.' } })
    expect(w.text()).toContain('Password salah.')

    await w.find('button[type="button"].btn-secondary').trigger('click')
    expect(w.emitted('batal')).toBeTruthy()
  })
})
