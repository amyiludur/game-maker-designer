#!/usr/bin/env node
/**
 * Validates every document under examples/ against its JSON Schema.
 *
 * The example game is a test fixture, not decoration: if it stops validating,
 * either the schemas or the example is wrong, and CI should say so.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import Ajv2020 from 'ajv/dist/2020.js'
import addFormats from 'ajv-formats'

const root = new URL('..', import.meta.url).pathname
const read = (p) => JSON.parse(readFileSync(join(root, p), 'utf8'))

const SCHEMAS = [
  'schemas/ability.schema.json',
  'schemas/game-system.schema.json',
  'schemas/card.schema.json',
  'schemas/set.schema.json',
  'schemas/deck.schema.json',
  'schemas/game-state.schema.json',
  'schemas/replay.schema.json',
  'schemas/bot-profile.schema.json',
]

// Which schema validates which example path.
const TARGETS = [
  ['examples/emberfall/game-system.json', 'game-system.schema.json'],
  ['examples/emberfall/sets', 'set.schema.json'],
  ['examples/emberfall/decks', 'deck.schema.json'],
  ['examples/emberfall/bots', 'bot-profile.schema.json'],
  ['examples/emberfall/replays', 'replay.schema.json'],
]

const BASE = 'https://game-maker-designer.dev/schemas/'

const ajv = new Ajv2020({ allErrors: true, strict: false, allowUnionTypes: true })
addFormats(ajv)
for (const path of SCHEMAS) ajv.addSchema(read(path))

const expand = (p) => {
  const full = join(root, p)
  if (!statSync(full).isDirectory()) return [p]
  return readdirSync(full).filter((f) => f.endsWith('.json')).map((f) => join(p, f))
}

let failures = 0
let checked = 0

for (const [target, schemaFile] of TARGETS) {
  const validate = ajv.getSchema(BASE + schemaFile)
  if (!validate) {
    console.error(`✗ schema not registered: ${schemaFile}`)
    failures++
    continue
  }
  for (const file of expand(target)) {
    checked++
    const doc = read(file)
    if (validate(doc)) {
      console.log(`✓ ${file}`)
    } else {
      failures++
      console.error(`✗ ${file}`)
      for (const err of validate.errors) {
        console.error(`    ${err.instancePath || '/'} ${err.message}`)
      }
    }
  }
}

// Cross-document integrity: the checks a JSON Schema cannot express.
const system = read('examples/emberfall/game-system.json')
const set = read('examples/emberfall/sets/core.json')
const cardsByCode = new Map(set.cards.map((c) => [c.code, c]))
const typeIds = new Set(system.cardTypes.map((t) => t.id))
const keywordIds = new Set((system.keywords ?? []).map((k) => k.id))
const traits = new Set(system.vocabularies?.traits ?? [])
const factions = new Set((system.vocabularies?.factions ?? []).map((f) => f.id))

const lint = []
for (const card of set.cards) {
  if (!typeIds.has(card.type)) lint.push(`${card.code}: unknown card type "${card.type}"`)
  if (card.faction && !factions.has(card.faction)) lint.push(`${card.code}: unknown faction "${card.faction}"`)
  for (const kw of card.keywords ?? []) {
    if (!keywordIds.has(kw.id)) lint.push(`${card.code}: unknown keyword "${kw.id}"`)
  }
  for (const trait of card.attributes?.traits ?? []) {
    if (!traits.has(trait)) lint.push(`${card.code}: trait "${trait}" is not in the vocabulary`)
  }
  const type = system.cardTypes.find((t) => t.id === card.type)
  for (const attr of type?.attributes ?? []) {
    if (attr.required && card.attributes?.[attr.id] === undefined) {
      lint.push(`${card.code}: missing required attribute "${attr.id}"`)
    }
  }
}

for (const deckFile of expand('examples/emberfall/decks')) {
  const deck = read(deckFile)
  const name = relative('examples/emberfall/decks', deckFile)
  const total = deck.cards.reduce((n, c) => n + c.count, 0)
  const { min, max } = system.deckbuilding.deckSize
  if (total < min || (max && total > max)) {
    lint.push(`${name}: ${total} cards, must be ${min}-${max}`)
  }
  const identity = cardsByCode.get(deck.identity)
  if (!identity) lint.push(`${name}: unknown identity "${deck.identity}"`)
  for (const entry of deck.cards) {
    const card = cardsByCode.get(entry.code)
    if (!card) { lint.push(`${name}: unknown card "${entry.code}"`); continue }
    if (entry.count > system.deckbuilding.maxCopies) {
      lint.push(`${name}: ${entry.count}x ${entry.code} exceeds the ${system.deckbuilding.maxCopies}-copy limit`)
    }
    if (entry.count > card.quantity) {
      lint.push(`${name}: ${entry.count}x ${entry.code} exceeds the ${card.quantity} printed in the set`)
    }
    if (identity && card.faction !== identity.faction && card.faction !== 'neutral') {
      lint.push(`${name}: ${entry.code} (${card.faction}) does not match hero faction ${identity.faction}`)
    }
  }
}

if (lint.length) {
  failures += lint.length
  console.error('\n✗ cross-document integrity:')
  for (const l of lint) console.error(`    ${l}`)
} else {
  console.log('✓ cross-document integrity (card types, vocabularies, keywords, deck legality)')
}

console.log(`\n${checked} documents checked, ${failures} problem(s).`)
process.exit(failures ? 1 : 0)
