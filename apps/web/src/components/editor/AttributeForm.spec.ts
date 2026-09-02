import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import AttributeForm from './AttributeForm.vue'
import type { FormField } from '@/api/types'
import { Rand } from '@/test/random'

const vocabularies = { traits: ['beast', 'ember', 'soldier'], rarities: ['common', 'rare'] }

function form(fields: FormField[], model: Record<string, unknown> = {}) {
  return mount(AttributeForm, { props: { fields, vocabularies, modelValue: model } })
}

/** The last value the component asked its parent to hold. */
function emitted(wrapper: ReturnType<typeof form>): Record<string, unknown> {
  const updates = wrapper.emitted('update:modelValue') as Record<string, unknown>[][] | undefined
  return updates?.at(-1)?.[0] ?? {}
}

describe('AttributeForm', () => {
  it('builds a control per field from the compiled descriptor', () => {
    const wrapper = form([
      { id: 'cost', name: 'Cost', type: 'integer', min: 0, max: 9, required: true },
      { id: 'rarity', name: 'Rarity', type: 'enum', options: vocabularies.rarities },
      { id: 'traits', name: 'Traits', type: 'tagList', vocabulary: 'traits' },
      { id: 'unique', name: 'Unique', type: 'boolean' },
      { id: 'flavor', name: 'Flavour', type: 'text' },
      { id: 'title', name: 'Title', type: 'string' },
    ])

    expect(wrapper.find('#f-cost').attributes('type')).toBe('number')
    expect(wrapper.find('#f-cost').attributes('max')).toBe('9')
    expect(wrapper.find('select#f-rarity').findAll('option')).toHaveLength(3)
    expect(wrapper.findAll('.tag')).toHaveLength(3)
    expect(wrapper.find('#f-unique').attributes('type')).toBe('checkbox')
    expect(wrapper.find('textarea#f-flavor').exists()).toBe(true)
    expect(wrapper.find('#f-title').attributes('type')).toBe('text')
  })

  it('names no field of its own — a game with different attributes gets a different form', () => {
    // The test that keeps this a platform rather than an Emberfall editor.
    const wrapper = form([
      { id: 'thwart', name: 'Thwart', type: 'integer' },
      { id: 'scheme', name: 'Scheme', type: 'integer' },
    ])

    expect(wrapper.text()).toContain('Thwart')
    expect(wrapper.text()).toContain('Scheme')
    expect(wrapper.text()).not.toContain('Attack')
    expect(wrapper.findAll('.field')).toHaveLength(2)
  })

  it('renders a card type with no attributes at all', () => {
    const wrapper = form([])

    expect(wrapper.findAll('.field')).toHaveLength(0)
    expect(wrapper.find('.form').exists()).toBe(true)
  })

  it('renders twenty attributes without collapsing any of them', () => {
    const fields: FormField[] = Array.from({ length: 20 }, (_, i) => ({
      id: `a${i}`,
      name: `Attribute ${i}`,
      type: 'integer',
    }))

    const wrapper = form(fields)

    expect(wrapper.findAll('.field')).toHaveLength(20)
    expect(wrapper.findAll('input[type=number]')).toHaveLength(20)
  })

  it('shows the constraint next to the field instead of saving it for an error', () => {
    const wrapper = form([
      { id: 'cost', name: 'Cost', type: 'integer', constraint: '0–9', required: true, perPlayer: true },
    ])

    expect(wrapper.find('.constraint').text()).toBe('0–9')
    expect(wrapper.find('.required').exists()).toBe(true)
    expect(wrapper.find('.hint').text()).toContain('per player')
  })

  it('offers every vocabulary member, including the ones this card does not have', async () => {
    const wrapper = form([{ id: 'traits', name: 'Traits', type: 'tagList', vocabulary: 'traits' }], {
      traits: ['beast'],
    })

    expect(wrapper.findAll('.tag.on').map((el) => el.text())).toEqual(['beast'])

    await wrapper.findAll('.tag')[1]!.trigger('click')
    expect(emitted(wrapper)).toEqual({ traits: ['beast', 'ember'] })
  })

  it('removes a tag that is already on', async () => {
    const wrapper = form([{ id: 'traits', name: 'Traits', type: 'tagList', vocabulary: 'traits' }], {
      traits: ['beast', 'ember'],
    })

    await wrapper.findAll('.tag')[0]!.trigger('click')
    expect(emitted(wrapper)).toEqual({ traits: ['ember'] })
  })

  it('leaves attributes it was not asked about untouched', async () => {
    // The form only knows about its own fields; a document attribute with no field must
    // survive an edit rather than be dropped on save.
    const wrapper = form([{ id: 'cost', name: 'Cost', type: 'integer' }], { cost: 1, legacyField: 'keep me' })

    const input = wrapper.find('#f-cost')
    ;(input.element as HTMLInputElement).value = '4'
    await input.trigger('input')

    expect(emitted(wrapper)).toEqual({ cost: 4, legacyField: 'keep me' })
  })

  it('round-trips a generated document through the form without changing it', async () => {
    // Doc 13's lossless-round-trip property, at the level the form actually edits: render
    // the document, touch a field, read the model back, and assert only that field moved.
    const types: FormField['type'][] = ['integer', 'string', 'text', 'boolean', 'enum', 'tagList']

    for (let seed = 1; seed <= 40; seed++) {
      const rand = new Rand(seed)
      const fields: FormField[] = Array.from({ length: 1 + rand.int(8) }, (_, i) => {
        const type = rand.pick(types)
        return {
          id: `f${i}`,
          name: `Field ${i}`,
          type,
          ...(type === 'enum' ? { options: vocabularies.rarities } : {}),
          ...(type === 'tagList' ? { vocabulary: 'traits' } : {}),
        }
      })

      const model: Record<string, unknown> = {}
      for (const field of fields) {
        if (field.type === 'integer') model[field.id] = rand.int(10)
        else if (field.type === 'boolean') model[field.id] = rand.bool()
        else if (field.type === 'enum') model[field.id] = rand.pick(vocabularies.rarities)
        else if (field.type === 'tagList') model[field.id] = [rand.pick(vocabularies.traits)]
        else model[field.id] = `v${rand.int(100)}`
      }

      const wrapper = form(fields, model)
      const target = fields.find((field) => field.type === 'string' || field.type === 'text')
      if (target === undefined) continue

      const control = wrapper.find(`#f-${target.id}`)
      ;(control.element as HTMLInputElement | HTMLTextAreaElement).value = 'edited'
      await control.trigger('input')

      expect(emitted(wrapper), `seed ${seed}`).toEqual({ ...model, [target.id]: 'edited' })
    }
  })
})
