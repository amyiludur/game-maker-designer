import { afterEach, describe, expect, it, vi } from 'vitest'

import { ApiError_, api } from './client'

function respond(status: number, body: unknown): void {
  vi.stubGlobal(
    'fetch',
    vi.fn(
      async () =>
        new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }),
    ),
  )
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('api client', () => {
  it('unwraps the data envelope', async () => {
    respond(200, { data: [{ id: 'g1', slug: 'emberfall' }] })

    await expect(api.games()).resolves.toEqual([{ id: 'g1', slug: 'emberfall' }])
    expect(fetch).toHaveBeenCalledWith('/api/v1/games', expect.anything())
  })

  it('recognises a stale version and carries the fresh position on the error', async () => {
    respond(409, {
      error: { code: 'stale_version', message: 'The match moved on.' },
      data: { version: 12 },
    })

    const error = await api.act('m1', { side: 'p0', actionId: 'pass' }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError_)
    expect((error as ApiError_).isStale).toBe(true)
    expect((error as ApiError_).data).toEqual({ version: 12 })
  })

  it('does not treat any other 409 as stale', async () => {
    respond(409, { error: { code: 'match_finished', message: 'Already over.' } })

    const error = (await api
      .act('m1', { side: 'p0', actionId: 'pass' })
      .catch((e: unknown) => e)) as ApiError_
    expect(error.isStale).toBe(false)
  })

  it('surfaces schema violations by JSON Pointer so they can sit next to the field', async () => {
    respond(422, {
      error: {
        code: 'validation_failed',
        message: 'The document was refused.',
        details: { violations: [{ pointer: '/attributes/cost', message: 'must be <= 9' }] },
      },
    })

    const error = (await api.saveCard('emberfall', 'EMB-001', {}).catch((e: unknown) => e)) as ApiError_
    expect(error.violations).toEqual([{ pointer: '/attributes/cost', message: 'must be <= 9' }])
  })

  it('reports an empty violation list rather than undefined when there is none', async () => {
    respond(500, { error: { code: 'server_error', message: 'Boom.' } })

    const error = (await api.saveCard('emberfall', 'EMB-001', {}).catch((e: unknown) => e)) as ApiError_
    expect(error.violations).toEqual([])
    expect(error.message).toBe('Boom.')
  })

  it('encodes array filters the way the API reads them', async () => {
    respond(200, { data: [], meta: { page: 1, perPage: 50, total: 0 } })

    await api.cards('emberfall', { type: ['character', 'spell'], q: 'ember', faction: [], cost: undefined })

    const url = vi.mocked(fetch).mock.calls[0]![0] as string
    expect(url).toBe('/api/v1/games/emberfall/cards?type%5B%5D=character&type%5B%5D=spell&q=ember')
  })

  it('returns the page envelope intact for a listing', async () => {
    respond(200, { data: [{ code: 'EMB-001' }], meta: { page: 2, perPage: 50, total: 84 } })

    const page = await api.cards('emberfall')
    expect(page.meta.total).toBe(84)
    expect(page.data).toHaveLength(1)
  })

  it('falls back to the status line when the body is not JSON', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => new Response('<html>502</html>', { status: 502, statusText: 'Bad Gateway' })),
    )

    const error = (await api.games().catch((e: unknown) => e)) as ApiError_
    expect(error.status).toBe(502)
    expect(error.error.code).toBe('http_502')
  })
})
