/** @vitest-environment jsdom */
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { ref } from 'vue'
import { createSSEConnection } from '../src/utils/sseUtils'

describe('createSSEConnection', () => {
  let mockAxios
  let mockEventSource
  let mockCurrentSchedule
  let mockLastEventId
  let mockIsConnected
  let mockRefreshSchedule
  let mockOnMessage
  let mockAutoRefresh
  let mockToken

  beforeEach(() => {
    mockToken = ref('test-token')
    mockAxios = {
      get: vi.fn()
    }
    mockEventSource = vi.fn().mockImplementation(function(url) {
      return {
        url,
        close: vi.fn(),
        readyState: 1,
        onopen: null,
        onmessage: null,
        onerror: null
      }
    })
    mockEventSource.CLOSED = 2
    mockCurrentSchedule = ref({ hashed_id: 'sched1' })
    mockLastEventId = ref(null)
    mockIsConnected = ref(false)
    mockRefreshSchedule = vi.fn()
    mockOnMessage = vi.fn()
    mockAutoRefresh = ref(true)

    vi.stubGlobal('setTimeout', vi.fn())
    vi.stubGlobal('clearTimeout', vi.fn())
  })

  it('sets up SSE connection correctly', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        status: 'success',
        data: { url: 'http://mercure.test/.well-known/mercure?topic=foo', token: 'm-token' }
      }
    })

    const { setupSSE } = createSSEConnection({
      token: mockToken,
      apiBase: 'http://api.test',
      currentSchedule: mockCurrentSchedule,
      lastEventId: mockLastEventId,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios
    })

    await setupSSE()

    expect(mockAxios.get).toHaveBeenCalledWith('http://api.test/schedules/subscription', expect.objectContaining({
      params: { schedule_id: 'sched1' }
    }))
    expect(mockEventSource).toHaveBeenCalledWith(expect.stringContaining('authorization=m-token'))
  })

  it('includes authorization token in URL', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        status: 'success',
        data: { url: 'http://mercure.test/.well-known/mercure?topic=foo', token: 'm-token' }
      }
    })

    const { setupSSE } = createSSEConnection({
      token: mockToken,
      apiBase: 'http://api.test',
      currentSchedule: mockCurrentSchedule,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios
    })

    await setupSSE()

    expect(mockEventSource).toHaveBeenCalledWith(expect.stringContaining('authorization=m-token'))
  })

  it('sets isConnected to true on message', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        status: 'success',
        data: { url: 'url', token: 'token' }
      }
    })

    const { setupSSE } = createSSEConnection({
      token: mockToken,
      apiBase: 'api',
      currentSchedule: mockCurrentSchedule,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios
    })

    await setupSSE()
    const sseInstance = mockEventSource.mock.results[0].value
    
    await sseInstance.onmessage({ lastEventId: 'server-id', data: '{"msg":"1"}' })

    expect(mockIsConnected.value).toBe(true)
    expect(mockOnMessage).toHaveBeenCalledWith('{"msg":"1"}')
  })

  it('fetches schedule on reconnect after manual close', async () => {
    mockAxios.get.mockResolvedValue({
      data: {
        status: 'success',
        data: { url: 'url', token: 'token' }
      }
    })

    const { setupSSE } = createSSEConnection({
      token: mockToken,
      apiBase: 'api',
      currentSchedule: mockCurrentSchedule,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios
    })

    // First connection
    await setupSSE()
    const sseInstance1 = mockEventSource.mock.results[0].value
    sseInstance1.onopen({})
    expect(mockRefreshSchedule).not.toHaveBeenCalled()

    // Second connection (reconnect)
    await setupSSE()
    const sseInstance2 = mockEventSource.mock.results[1].value
    sseInstance2.onopen({})

    expect(mockIsConnected.value).toBe(true)
    expect(mockRefreshSchedule).toHaveBeenCalled()
  })

  it('does NOT fetch schedule on initial connection open', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        status: 'success',
        data: { url: 'url', token: 'token' }
      }
    })

    const { setupSSE } = createSSEConnection({
      token: mockToken,
      apiBase: 'api',
      currentSchedule: mockCurrentSchedule,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios
    })

    await setupSSE()
    const sseInstance = mockEventSource.mock.results[0].value
    sseInstance.onopen({})

    expect(mockIsConnected.value).toBe(true)
    expect(mockRefreshSchedule).not.toHaveBeenCalled()
  })

  it('prevents multiple concurrent connections', async () => {
    let resolveAxios
    const axiosPromise = new Promise(resolve => { resolveAxios = resolve })
    mockAxios.get.mockReturnValue(axiosPromise)

    const { setupSSE } = createSSEConnection({
      token: mockToken,
      apiBase: 'api',
      currentSchedule: mockCurrentSchedule,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios
    })

    const p1 = setupSSE()
    const p2 = setupSSE()

    resolveAxios({
      data: {
        status: 'success',
        data: { url: 'url', token: 'token' }
      }
    })

    await Promise.all([p1, p2])

    expect(mockAxios.get).toHaveBeenCalledTimes(1)
  })

  it('schedules reconnect on error', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        status: 'success',
        data: { url: 'url', token: 'token' }
      }
    })

    const { setupSSE } = createSSEConnection({
      token: mockToken,
      apiBase: 'api',
      currentSchedule: mockCurrentSchedule,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios,
      reconnectErrorDelay: 1234
    })

    await setupSSE()
    const sseInstance = mockEventSource.mock.results[0].value
    sseInstance.readyState = 2 // CLOSED
    
    sseInstance.onerror({})

    expect(mockIsConnected.value).toBe(false)
    expect(globalThis.setTimeout).toHaveBeenCalledWith(expect.any(Function), 1234)
  })

  it('closes connection and clears timeout on close()', async () => {
    mockAxios.get.mockResolvedValueOnce({
      data: {
        status: 'success',
        data: { url: 'url', token: 'token' }
      }
    })

    const { setupSSE, close } = createSSEConnection({
      token: mockToken,
      apiBase: 'api',
      currentSchedule: mockCurrentSchedule,
      isConnected: mockIsConnected,
      refreshSchedule: mockRefreshSchedule,
      onMessage: mockOnMessage,
      autoRefresh: mockAutoRefresh,
      EventSourceClass: mockEventSource,
      axios: mockAxios
    })

    await setupSSE()
    const sseInstance = mockEventSource.mock.results[0].value
    
    // Manual setup of timeout to test close()
    const mockTimeout = 123
    globalThis.setTimeout.mockReturnValue(mockTimeout)
    // Directly call error to trigger internal reconnect logic
    sseInstance.readyState = 2 // CLOSED
    sseInstance.onerror({})

    close()

    expect(sseInstance.close).toHaveBeenCalled()
    expect(mockIsConnected.value).toBe(false)
    expect(globalThis.clearTimeout).toHaveBeenCalledWith(mockTimeout)
  })
})
