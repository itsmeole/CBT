import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfirmLogoutModal from './ConfirmLogoutModal.vue'

describe('ConfirmLogoutModal', () => {
  it('does not render dialog when modelValue is false', () => {
    const wrapper = mount(ConfirmLogoutModal, {
      props: {
        modelValue: false
      }
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('renders dialog when modelValue is true with custom or default text', () => {
    const wrapper = mount(ConfirmLogoutModal, {
      props: {
        modelValue: true,
        title: 'Konfirmasi Keluar CBT',
        message: 'Yakin ingin keluar?'
      }
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Konfirmasi Keluar CBT')
    expect(wrapper.text()).toContain('Yakin ingin keluar?')
  })

  it('emits cancel and update:modelValue on Batal click', async () => {
    const wrapper = mount(ConfirmLogoutModal, {
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

  it('emits confirm on Ya, Keluar click', async () => {
    const wrapper = mount(ConfirmLogoutModal, {
      props: {
        modelValue: true
      }
    })
    const confirmButton = wrapper.findAll('button')[1]
    expect(confirmButton.text()).toContain('Ya, Keluar')
    await confirmButton.trigger('click')

    expect(wrapper.emitted('confirm')).toBeTruthy()
  })

  it('disables buttons when loading is true', () => {
    const wrapper = mount(ConfirmLogoutModal, {
      props: {
        modelValue: true,
        loading: true
      }
    })
    const buttons = wrapper.findAll('button')
    expect(buttons[0].attributes('disabled')).toBeDefined()
    expect(buttons[1].attributes('disabled')).toBeDefined()
  })
})

