#!/usr/bin/env node
/**
 * Validates every document under examples/ against its JSON Schema, then runs the
 * cross-document integrity checks a JSON Schema cannot express.
 *
 * The example games are test fixtures, not decoration: if one stops validating,
 * either the schemas or the example is wrong, and CI should say so.
 */
import { readFileSync, readdirSync, existsSync, statSync } from 'node:fs'
import { join } from 'node:path'
import Ajv2020 from 'ajv/dist/2020.js'
import addFormats from 'ajv-formats'

const root = new URL('..', import.meta.url).pathname
const read = (p) => JSON.parse(readFileSync(join(root, p), 'utf8'))
const BASE = 'https://game-maker-designer.dev/schemas/'

const SCHEMAS = [
  'ability', 'game-system', 'card', 'set', 'deck',
  'game-state', 'replay', 'bot-profile', 'scenario', 'encounter-set',
]

/** Directory name -> schema, applied to every .json file in that directory. */
const DIRS = {
  sets: 'set',
  decks: 'deck',
  bots: 'bot-profile',
  replays: 'replay',
  scenarios: 'scenario',
  'encounter-sets': 'encounter-set',
}

const ajv = new Ajv2020({ allErrors: true, strict: false, allowUnionTypes: true })
addFormats(ajv)
for (const name of SCHEMAS) ajv.addSchema(read(`schemas/${name}.schema.json`))

const games = readdirSync(join(root, 'examples')).filter((d) =>
  statSync(join(root, 'examples', d)).isDirectory()
)

let failures = 0
let checked = 0

const check = (file, schemaName) => {
  checked++
  const validate = ajv.getSchema(BASE + schemaName + '.schema.json')
  if (validate(read(file))) {
    console.log(`✓ ${file}`)
  } else {
    failures++
    console.error(`✗ ${file}`)
    for (const err of validate.errors) console.error(`    ${err.instancePath || '/'} ${err.message}`)
  }
}

for (const game of games) {
  const base = `examples/${game}`
  check(`${base}/game-system.json`, 'game-system')
  for (const [dir, schema] of Object.entries(DIRS)) {
    const path = join(root, base, dir)
    if (!existsSync(path)) continue
    for (const f of readdirSync(path).filter((f) => f.endsWith('.json'))) {
      check(`${base}/${dir}/${f}`, schema)
    }
  }
}

// ---------------------------------------------------------------------------
// Cross-document integrity
// ---------------------------------------------------------------------------

console.log('')

/** A card may be flat or double-sided; yield each face as a (type, attributes, keywords) view. */
const facesOf = (card) =>
  card.sides
    ? Object.entries(card.sides).map(([face, s]) => ({ face, ...s }))
    : [{ face: 'front', name: card.name, type: card.type, attributes: card.attributes, keywords: card.keywords }]

const lint = []

for (const game of games) {
  const base = `examples/${game}`
  const system = read(`${base}/game-system.json`)
  const typeIds = new Set(system.cardTypes.map((t) => t.id))
  const keywordIds = new Set((system.keywords ?? []).map((k) => k.id))
  const traits = new Set(system.vocabularies?.traits ?? [])
  const factions = new Set((system.vocabularies?.factions ?? []).map((f) => f.id))
  const adversaryIds = new Set((system.adversaries ?? []).map((a) => a.id))
  const zoneIds = new Set(system.zones.map((z) => z.id))

  const cards = new Map()
  const setsDir = join(root, base, 'sets')
  if (existsSync(setsDir)) {
    for (const f of readdirSync(setsDir).filter((f) => f.endsWith('.json'))) {
      for (const card of read(`${base}/sets/${f}`).cards ?? []) cards.set(card.code, card)
    }
  }

  const at = (what) => `${game}: ${what}`

  // Zones declared for an adversary must exist and be scoped to it.
  for (const adv of system.adversaries ?? []) {
    for (const z of adv.zones ?? []) {
      const zone = system.zones.find((x) => x.id === z)
      if (!zone) lint.push(at(`adversary "${adv.id}" declares unknown zone "${z}"`))
      else if (zone.scope !== 'adversary' || zone.side !== adv.id) {
        lint.push(at(`zone "${z}" is claimed by adversary "${adv.id}" but is not scoped to it`))
      }
    }
    for (const anchor of adv.anchors ?? []) {
      if (!typeIds.has(anchor.type)) lint.push(at(`anchor "${anchor.id}" has unknown card type "${anchor.type}"`))
      if (anchor.zone && !zoneIds.has(anchor.zone)) lint.push(at(`anchor "${anchor.id}" names unknown zone "${anchor.zone}"`))
    }
  }

  // Cards: types, factions, keywords, traits, required attributes — per face.
  for (const card of cards.values()) {
    if (card.faction && !factions.has(card.faction)) lint.push(at(`${card.code}: unknown faction "${card.faction}"`))
    for (const face of facesOf(card)) {
      const where = card.sides ? `${card.code} (${face.face})` : card.code
      if (!typeIds.has(face.type)) { lint.push(at(`${where}: unknown card type "${face.type}"`)); continue }
      for (const kw of face.keywords ?? []) {
        if (!keywordIds.has(kw.id)) lint.push(at(`${where}: unknown keyword "${kw.id}"`))
      }
      for (const trait of face.attributes?.traits ?? []) {
        if (!traits.has(trait)) lint.push(at(`${where}: trait "${trait}" is not in the vocabulary`))
      }
      const type = system.cardTypes.find((t) => t.id === face.type)
      for (const attr of type?.attributes ?? []) {
        if (attr.required && face.attributes?.[attr.id] === undefined) {
          lint.push(at(`${where}: missing required attribute "${attr.id}"`))
        }
      }
      if (card.sides && !type?.doubleSided) {
        lint.push(at(`${where}: card has sides but type "${face.type}" is not marked doubleSided`))
      }
    }
  }

  // Decks.
  const decksDir = join(root, base, 'decks')
  if (existsSync(decksDir)) {
    for (const f of readdirSync(decksDir).filter((f) => f.endsWith('.json'))) {
      const deck = read(`${base}/decks/${f}`)
      const total = deck.cards.reduce((n, c) => n + c.count, 0)
      const { min, max } = system.deckbuilding?.deckSize ?? {}
      if (min && (total < min || (max && total > max))) lint.push(at(`${f}: ${total} cards, must be ${min}-${max}`))
      const identity = cards.get(deck.identity)
      if (!identity) lint.push(at(`${f}: unknown identity "${deck.identity}"`))
      for (const entry of deck.cards) {
        const card = cards.get(entry.code)
        if (!card) { lint.push(at(`${f}: unknown card "${entry.code}"`)); continue }
        if (entry.count > (system.deckbuilding?.maxCopies ?? Infinity)) {
          lint.push(at(`${f}: ${entry.count}x ${entry.code} exceeds the ${system.deckbuilding.maxCopies}-copy limit`))
        }
        if (entry.count > card.quantity) {
          lint.push(at(`${f}: ${entry.count}x ${entry.code} exceeds the ${card.quantity} printed in the set`))
        }
        // Faction legality: match the identity, or a neutral faction if the game has one.
        if (identity && card.faction !== identity.faction && card.faction !== 'neutral') {
          lint.push(at(`${f}: ${entry.code} (${card.faction}) does not match identity faction ${identity.faction}`))
        }
      }
    }
  }

  // Encounter sets.
  const encDir = join(root, base, 'encounter-sets')
  const encounterSets = new Map()
  if (existsSync(encDir)) {
    for (const f of readdirSync(encDir).filter((f) => f.endsWith('.json'))) {
      const set = read(`${base}/encounter-sets/${f}`)
      encounterSets.set(set.code, set)
      for (const entry of set.cards) {
        const card = cards.get(entry.code)
        if (!card) { lint.push(at(`${f}: unknown card "${entry.code}"`)); continue }
        const type = system.cardTypes.find((t) => t.id === facesOf(card)[0].type)
        if (type?.controlledBy !== 'adversary') {
          lint.push(at(`${f}: ${entry.code} is a player card and cannot be in an encounter set`))
        }
      }
    }
  }

  // Scenarios.
  const scnDir = join(root, base, 'scenarios')
  if (existsSync(scnDir)) {
    for (const f of readdirSync(scnDir).filter((f) => f.endsWith('.json'))) {
      const scenario = read(`${base}/scenarios/${f}`)
      if (!adversaryIds.has(scenario.adversary)) {
        lint.push(at(`${f}: unknown adversary "${scenario.adversary}"`))
      }
      const adv = (system.adversaries ?? []).find((a) => a.id === scenario.adversary)
      for (const anchor of adv?.anchors ?? []) {
        const filled = scenario.anchors?.[anchor.id]
        if (anchor.required !== false && !filled) {
          lint.push(at(`${f}: required anchor "${anchor.id}" is not filled`))
          continue
        }
        for (const code of [filled].flat().filter(Boolean)) {
          const card = cards.get(code)
          if (!card) { lint.push(at(`${f}: anchor "${anchor.id}" names unknown card "${code}"`)); continue }
          if (!facesOf(card).some((s) => s.type === anchor.type)) {
            lint.push(at(`${f}: anchor "${anchor.id}" expects type "${anchor.type}" but ${code} has none`))
          }
        }
      }
      for (const code of scenario.encounterSets ?? []) {
        if (!encounterSets.has(code)) lint.push(at(`${f}: unknown encounter set "${code}"`))
      }
      const required = system.scenarioBuilding?.encounterSets?.required ?? []
      for (const code of required) {
        if (!(scenario.encounterSets ?? []).includes(code)) {
          lint.push(at(`${f}: required encounter set "${code}" is missing`))
        }
      }
      const max = scenario.playerCount?.max
      if (max && max > system.players.max) {
        lint.push(at(`${f}: allows ${max} players but the system caps at ${system.players.max}`))
      }
    }
  }
}

// Card counts vs. each set's design.budget. Not a failure — a budget gap is a legitimate
// design state (it is what the completeness view exists to show) — but printing it keeps
// prose that quotes counts honest.
for (const game of games) {
  const setsDir = join(root, `examples/${game}/sets`)
  if (!existsSync(setsDir)) continue
  for (const f of readdirSync(setsDir).filter((f) => f.endsWith('.json'))) {
    const set = read(`examples/${game}/sets/${f}`)
    const actual = {}
    for (const card of set.cards ?? []) {
      const type = facesOf(card)[0].type
      actual[type] = (actual[type] ?? 0) + 1
    }
    const total = (set.cards ?? []).length
    const budget = set.design?.budget
    const planned = budget ? Object.values(budget).reduce((a, b) => a + b, 0) : null
    const gaps = budget
      ? Object.entries(budget).filter(([t, n]) => (actual[t] ?? 0) !== n)
          .map(([t, n]) => `${t} ${actual[t] ?? 0}/${n}`)
      : []
    console.log(
      `  ${game}/${f}: ${total} cards` +
      (planned ? ` (budget ${planned}${gaps.length ? `, gaps: ${gaps.join(', ')}` : ', met'})` : '')
    )
  }
}

if (lint.length) {
  failures += lint.length
  console.error('\n✗ cross-document integrity:')
  for (const l of lint) console.error(`    ${l}`)
} else {
  console.log('✓ cross-document integrity (card types, vocabularies, keywords, deck legality, adversary zones, anchors, encounter sets)')
}

console.log(`\n${checked} documents checked across ${games.length} game(s), ${failures} problem(s).`)
process.exit(failures ? 1 : 0)
