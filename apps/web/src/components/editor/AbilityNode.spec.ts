import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AbilityNode from './AbilityNode.vue'
import { Rand } from '@/test/random'
import { randomAbility } from '@/test/abilities'

/**
 * Every scalar an ability holds, as `key=value`.
 *
 * `op` is excluded because the component gives it its own chip rather than a key/value
 * pair; `text` because it is the generated reminder line, rendered separately by the editor.
 */
function leaves(node: unknown, into: string[] = []): string[] {
  if (Array.isArray(node)) {
    for (const child of node) leaves(child, into)
    return into
  }
  if (typeof node === 'object' && node !== null) {
    for (const [key, value] of Object.entries(node)) {
      if (key === 'text' || key === 'op') continue
      if (typeof value === 'object' && value !== null) leaves(value, into)
      else into.push(`${key}=${String(value)}`)
    }
    return into
  }
  return into
}

/** Every `op` in the tree, in document order. */
function ops(node: unknown, into: string[] = []): string[] {
  if (Array.isArray(node)) {
    for (const child of node) ops(child, into)
    return into
  }
  if (typeof node === 'object' && node !== null) {
    const record = node as Record<string, unknown>
    if (typeof record.op === 'string') into.push(record.op)
    for (const value of Object.values(record)) {
      if (typeof value === 'object' && value !== null) ops(value, into)
    }
    return into
  }
  return into
}

describe('AbilityNode', () => {
  it('renders the op of every node in the tree', () => {
    const ability = {
      id: 'a1',
      kind: 'triggered',
      effect: { op: 'sequence', effects: [{ op: 'draw', amount: 1 }, { op: 'deal_damage', target: '$target', amount: 2 }] },
    }

    const wrapper = mount(AbilityNode, { props: { node: ability } })
    const text = wrapper.text()

    expect(text).toContain('sequence')
    expect(text).toContain('draw')
    expect(text).toContain('deal_damage')
  })

  it('marks DSL selectors so they do not read as literal strings', () => {
    const wrapper = mount(AbilityNode, { props: { node: { op: 'destroy', target: '$self', amount: 1 } } })

    const selectors = wrapper.findAll('.value.selector').map((el) => el.text())
    expect(selectors).toEqual(['$self'])
  })

  it('nests one level of indent per level of the tree', () => {
    const wrapper = mount(AbilityNode, {
      props: { node: { op: 'if', then: { op: 'sequence', effects: [{ op: 'draw', amount: 1 }] } } },
    })

    // The root carries no badge, so the sequence under `then` is L1 and the effect inside
    // it is L2 — the indent, the rule and the background step all step with them.
    expect(wrapper.findAll('.badge').map((el) => el.text())).toEqual(['L1', 'L2'])
  })

  it('loses nothing from a generated ability', () => {
    // The property doc 13 asks for: what the builder shows must be everything the document
    // holds. A node type that silently drops a param is the failure this catches, and it is
    // the one that makes designers stop trusting the form.
    for (let seed = 1; seed <= 60; seed++) {
      const ability = randomAbility(new Rand(seed))
      const wrapper = mount(AbilityNode, { props: { node: ability } })
      const rendered = wrapper.findAll('.pair').map((el) => {
        const key = el.find('.key').text()
        const value = el.find('.value').text()
        return `${key}=${value}`
      })

      expect(rendered.slice().sort(), `seed ${seed}`).toEqual(leaves(ability).slice().sort())

      const renderedOps = wrapper.findAll('.op').map((el) => el.text())
      expect(renderedOps.slice().sort(), `seed ${seed}`).toEqual(ops(ability).slice().sort())
    }
  })

  it('renders a scalar node without throwing', () => {
    // Arrays of strings appear in `traits`-style params, and the recursion reaches them.
    const wrapper = mount(AbilityNode, { props: { node: { op: 'add_trait', traits: ['beast', 'ember'] } } })
    expect(wrapper.text()).toContain('add_trait')
  })
})
