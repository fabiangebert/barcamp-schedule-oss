import { describe, it, expect, vi } from 'vitest'
import { createStalenessDetector } from '../src/utils/stalenessDetector'

describe('createStalenessDetector', () => {
  it('triggers onStale if hidden duration exceeds threshold', () => {
    const onStale = vi.fn()
    const thresholdMs = 1000
    let currentTime = 10000
    const nowFn = () => currentTime
    
    let visibilityState = 'visible'
    let eventCallback
    const documentObj = {
      get visibilityState() { return visibilityState },
      addEventListener: vi.fn((event, cb) => {
        if (event === 'visibilitychange') eventCallback = cb
      }),
      removeEventListener: vi.fn()
    }
    const windowObj = {
      addEventListener: vi.fn(),
      removeEventListener: vi.fn()
    }

    const detector = createStalenessDetector({ onStale, thresholdMs, documentObj, windowObj, nowFn })
    detector.start()

    // Hide at 10000
    visibilityState = 'hidden'
    eventCallback()

    // Show at 11001 (threshold exceeded)
    currentTime = 11001
    visibilityState = 'visible'
    eventCallback()

    expect(onStale).toHaveBeenCalledWith(1001)
  })

  it('does NOT trigger onStale if hidden duration is within threshold', () => {
    const onStale = vi.fn()
    const thresholdMs = 1000
    let currentTime = 10000
    const nowFn = () => currentTime
    
    let visibilityState = 'visible'
    let eventCallback
    const documentObj = {
      get visibilityState() { return visibilityState },
      addEventListener: vi.fn((event, cb) => {
        if (event === 'visibilitychange') eventCallback = cb
      }),
      removeEventListener: vi.fn()
    }
    const windowObj = {
      addEventListener: vi.fn(),
      removeEventListener: vi.fn()
    }

    const detector = createStalenessDetector({ onStale, thresholdMs, documentObj, windowObj, nowFn })
    detector.start()

    // Hide at 10000
    visibilityState = 'hidden'
    eventCallback()

    // Show at 10999 (threshold NOT exceeded)
    currentTime = 10999
    visibilityState = 'visible'
    eventCallback()

    expect(onStale).not.toHaveBeenCalled()
  })

  it('resets hidden time after becoming visible', () => {
    const onStale = vi.fn()
    const thresholdMs = 1000
    let currentTime = 10000
    const nowFn = () => currentTime
    
    let visibilityState = 'visible'
    let eventCallback
    const documentObj = {
      get visibilityState() { return visibilityState },
      addEventListener: vi.fn((event, cb) => {
        if (event === 'visibilitychange') eventCallback = cb
      }),
      removeEventListener: vi.fn()
    }
    const windowObj = {
      addEventListener: vi.fn(),
      removeEventListener: vi.fn()
    }

    const detector = createStalenessDetector({ onStale, thresholdMs, documentObj, windowObj, nowFn })
    detector.start()

    // Hide-Show within threshold
    visibilityState = 'hidden'
    eventCallback()
    currentTime = 10500
    visibilityState = 'visible'
    eventCallback()
    expect(onStale).not.toHaveBeenCalled()

    // Hide again at 12000
    currentTime = 12000
    visibilityState = 'hidden'
    eventCallback()

    // Show at 13001 (threshold exceeded)
    currentTime = 13001
    visibilityState = 'visible'
    eventCallback()
    expect(onStale).toHaveBeenCalledWith(1001)
  })

  it('correctly adds and removes event listener', () => {
    const onStale = vi.fn()
    const documentObj = {
      addEventListener: vi.fn(),
      removeEventListener: vi.fn()
    }
    const windowObj = {
      addEventListener: vi.fn(),
      removeEventListener: vi.fn()
    }

    const detector = createStalenessDetector({ onStale, documentObj, windowObj })
    
    detector.start()
    expect(documentObj.addEventListener).toHaveBeenCalledWith('visibilitychange', expect.any(Function))
    expect(windowObj.addEventListener).toHaveBeenCalledWith('focus', expect.any(Function))

    detector.stop()
    expect(documentObj.removeEventListener).toHaveBeenCalledWith('visibilitychange', expect.any(Function))
    expect(windowObj.removeEventListener).toHaveBeenCalledWith('focus', expect.any(Function))
  })
})
