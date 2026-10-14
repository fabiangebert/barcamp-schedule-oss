/**
 * @vitest-environment jsdom
 */
import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import NotificationModal from '../src/components/modals/NotificationModal.vue'
import BaseButton from '../src/components/base/BaseButton.vue'

describe('NotificationModal', () => {
  it('renders standard success modal with one button', () => {
    const wrapper = mount(NotificationModal, {
      props: {
        visible: true,
        type: 'success',
        confirmText: 'OK',
        closable: true
      },
      global: {
        stubs: {
          BaseModal: {
            template: '<div><slot name="footer"></slot></div>'
          },
          SuccessIcon: true,
          ErrorIcon: true
        }
      }
    })

    const baseButtons = wrapper.findAllComponents(BaseButton)
    expect(baseButtons.length).toBe(1)
    expect(baseButtons[0].text()).toContain('OK')
  })

  it('renders standard error modal with one button', () => {
    const wrapper = mount(NotificationModal, {
      props: {
        visible: true,
        type: 'error',
        confirmText: 'OK',
        closable: true
      },
      global: {
        stubs: {
          BaseModal: {
            template: '<div><slot name="footer"></slot></div>'
          },
          SuccessIcon: true,
          ErrorIcon: true
        }
      }
    })

    const baseButtons = wrapper.findAllComponents(BaseButton)
    expect(baseButtons.length).toBe(1)
  })

  it('renders info modal with two buttons', () => {
    const wrapper = mount(NotificationModal, {
      props: {
        visible: true,
        type: 'info',
        confirmText: 'Refresh',
        cancelText: 'Ignore',
        closable: true
      },
      global: {
        stubs: {
          BaseModal: {
            template: '<div><slot name="footer"></slot></div>'
          },
          SuccessIcon: true,
          ErrorIcon: true
        }
      }
    })

    const baseButtons = wrapper.findAllComponents(BaseButton)
    expect(baseButtons.length).toBe(2)
    expect(baseButtons[0].text()).toContain('Refresh')
    expect(baseButtons[1].text()).toContain('Ignore')
  })

  it('renders non-closable guest logout modal with exactly one button', () => {
    const wrapper = mount(NotificationModal, {
      props: {
        visible: true,
        type: 'error',
        confirmText: 'Logout',
        closable: false
      },
      global: {
        stubs: {
          BaseModal: {
            template: '<div><slot name="footer"></slot></div>'
          },
          SuccessIcon: true,
          ErrorIcon: true
        }
      }
    })

    const baseButtons = wrapper.findAllComponents(BaseButton)
    expect(baseButtons.length).toBe(1)
    expect(baseButtons[0].text()).toContain('Logout')
  })

  it('renders modal with custom confirm text and closable=true with two buttons', () => {
    const wrapper = mount(NotificationModal, {
      props: {
        visible: true,
        type: 'error',
        confirmText: 'Retry',
        closable: true
      },
      global: {
        stubs: {
          BaseModal: {
            template: '<div><slot name="footer"></slot></div>'
          },
          SuccessIcon: true,
          ErrorIcon: true
        }
      }
    })

    const baseButtons = wrapper.findAllComponents(BaseButton)
    expect(baseButtons.length).toBe(2)
    expect(baseButtons[0].text()).toContain('Retry')
    expect(baseButtons[1].text()).toContain('OK')
  })
})
