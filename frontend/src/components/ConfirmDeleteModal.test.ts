import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfirmDeleteModal from './ConfirmDeleteModal.vue'

describe('ConfirmDeleteModal', () => {
  it('does not render dialog when modelValue is false', () => {
    const wrapper = mount(ConfirmDeleteModal, {
      props: {
        modelValue: false
      }
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('renders dialog when modelValue is true with custom text', () => {
    const wrapper = mount(ConfirmDeleteModal, {
      props: {
        modelValue: true,
        title: 'Hapus Butir Soal',
        message: 'Apakah Anda yakin ingin menghapus butir soal ini?'
      }
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Hapus Butir Soal')
    expect(wrapper.text()).toContain('Apakah Anda yakin ingin menghapus butir soal ini?')
  })

  it('emits cancel and update:modelValue on Batal click', async () => {
    const wrapper = mount(ConfirmDeleteModal, {
      props: {
        modelValue: true
      }
    })
    const cancelButton = wrapper.findAll('button')[0]
    expect(cancelButton.text()).toBe('Batal')
    await cancelButton.trigger('click')

    expect(wrapper.emitted('cancel')).toBeTruthy()
    expect(wrapper.emitted('update:modelValue')).toEqual([[false]])
  })

  it('emits confirm on Ya, Hapus click', async () => {
    const wrapper = mount(ConfirmDeleteModal, {
      props: {
        modelValue: true
      }
    })
    const confirmButton = wrapper.findAll('button')[1]
    expect(confirmButton.text()).toContain('Ya, Hapus')
    await confirmButton.trigger('click')

    expect(wrapper.emitted('confirm')).toBeTruthy()
  })

  it('disables buttons and shows spinner text when loading is true', () => {
    const wrapper = mount(ConfirmDeleteModal, {
      props: {
        modelValue: true,
        loading: true
      }
    })
    const buttons = wrapper.findAll('button')
    expect(buttons[0].attributes('disabled')).toBeDefined()
    expect(buttons[1].attributes('disabled')).toBeDefined()
    expect(buttons[1].text()).toContain('Menghapus...')
  })
})

