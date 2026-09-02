#!/usr/bin/env node
/**
 * Generates TypeScript for the repository's JSON Schemas.
 *
 * The point is that the frontend cannot drift from the contract: the server validates
 * writes against these same files, and the kernel plays the documents they describe. A
 * hand-written `Card` interface would be a fourth opinion about the shape of a card, and
 * the one nobody would think to update.
 *
 * The output is committed and CI re-runs this to check it is current, so a schema change
 * the frontend has not caught up with fails the build rather than a runtime cast.
 *
 * Everything is compiled as one bundle rather than a file at a time. The schemas share
 * definitions — an ability, an effect, a query — and compiling them separately emits each
 * shared definition once per file under a name chosen to avoid a collision that only exists
 * within that file, so `AbilityEffect` in one output is `AbilityEffect1` in the next. One
 * bundle means one namespace and one name per definition.
 */
import { readdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { compile } from 'json-schema-to-typescript'

const here = dirname(fileURLToPath(import.meta.url))
const root = resolve(here, '../../..')
const schemas = join(root, 'schemas')
// A declaration file rather than a module: these are types and nothing else, and it means
// `skipLibCheck` covers the generated text while every use of it is still checked. The
// schemas are legal but not all of them are expressible in TypeScript — `vocabularies` has
// named optional properties alongside an `additionalProperties` index signature, which TS
// rejects because an optional property is `T | undefined` and the index type is not — and
// rewriting a correct schema to suit a code generator would be the wrong way round.
const out = resolve(here, '../src/api/documents.gen.d.ts')

const REMOTE = 'https://game-maker-designer.dev/schemas/'

// The state and replay schemas describe what the kernel exchanges, not what a designer
// authors; the API surfaces those through its own envelopes, hand-written in `types.ts`.
// They are still loaded, because a document schema may reference them.
const skip = new Set(['game-state.schema.json', 'replay.schema.json'])

const files = (await readdir(schemas)).filter((name) => name.endsWith('.schema.json')).sort()

const sources = {}
for (const file of files) {
  sources[file] = JSON.parse(await readFile(join(schemas, file), 'utf8'))
}

/** `card.schema.json` → `card`; the prefix every definition from that file carries. */
const prefix = (file) => file.replace('.schema.json', '').replace(/-/g, '_')

const defs = {}
const ingested = new Set()

/**
 * Rewrites `$ref`s to point inside the bundle.
 *
 * Schemas reference each other by `$id` URL, which is right for a published contract and
 * useless to a generator that must not reach the network. A local `#/$defs/x` inside a
 * definition still means the file that definition came from, so it is prefixed too.
 */
function rewrite(node, from) {
  if (Array.isArray(node)) return node.map((item) => rewrite(item, from))
  if (node === null || typeof node !== 'object') return node

  const result = {}
  for (const [key, value] of Object.entries(node)) {
    result[key] = key === '$ref' && typeof value === 'string' ? reref(value, from) : rewrite(value, from)
  }
  return result
}

function reref(ref, from) {
  if (ref.startsWith('#/$defs/')) return `#/$defs/${from}_${ref.slice('#/$defs/'.length)}`
  if (!ref.startsWith(REMOTE)) return ref

  const [file, fragment] = ref.slice(REMOTE.length).split('#')
  const name = ingest(file)

  return fragment === undefined || fragment === ''
    ? `#/$defs/${name}`
    : `#/$defs/${name}_${fragment.replace('/$defs/', '')}`
}

/** Copies one schema file into the bundle, with everything it references. */
function ingest(file) {
  const name = prefix(file)
  if (ingested.has(name)) return name
  ingested.add(name)

  const source = sources[file]
  if (source === undefined) throw new Error(`${file} is referenced but is not in schemas/`)

  for (const [def, value] of Object.entries(source.$defs ?? {})) {
    defs[`${name}_${def}`] = rewrite(value, name)
  }

  // `$id` goes: with it present, every `#/$defs/...` resolves against the published URL,
  // which the generator would have to fetch.
  const { $schema: _s, $id: _i, $defs: _d, ...rest } = source
  const body = rewrite(rest, name)

  // A schema whose root is a `$ref` — ability.schema.json is one — is legal JSON Schema
  // and the generator cannot read it, so the definition is inlined at the root.
  if (typeof body.$ref === 'string') {
    const { $ref, ...overrides } = body
    const referenced = defs[$ref.slice('#/$defs/'.length)]
    if (referenced === undefined) throw new Error(`root $ref ${$ref} has no definition`)

    defs[name] = { ...referenced, ...overrides }
    return name
  }

  defs[name] = body
  return name
}

const documents = files.filter((file) => !skip.has(file))
for (const file of documents) ingest(file)

// Each document's own name comes from its schema title, so `card.schema.json` produces
// `Card` and not `CardSchemaJson`.
for (const file of documents) {
  defs[prefix(file)] = { ...defs[prefix(file)], title: sources[file].title }
}

const bundle = {
  title: 'Documents',
  type: 'object',
  properties: Object.fromEntries(documents.map((file) => [prefix(file), { $ref: `#/$defs/${prefix(file)}` }])),
  required: documents.map((file) => prefix(file)),
  additionalProperties: false,
  $defs: defs,
}

const generated = await compile(bundle, 'Documents', {
  bannerComment: '',
  additionalProperties: false,
  declareExternallyReferenced: true,
  style: { semi: false, singleQuote: true, printWidth: 100 },
  cwd: schemas,
})

// The root exists only to give the compiler one entry point that reaches every document.
const blocks = generated
  .split(/\n(?=(?:\/\*\*[\s\S]*?\*\/\n)?export )/g)
  .map((block) => block.trim())
  .filter((block) => block !== '' && !/export interface Documents\b/.test(block))

const header = `/**
 * Generated from schemas/*.json by \`npm run types\`. Do not edit.
 *
 * These are the authored documents — the same files the API validates against and the
 * kernel plays. The API's own envelopes are hand-written in \`types.ts\`, because those are
 * the shape of a response rather than the shape of a document.
 */

/* eslint-disable */
`

await writeFile(out, `${header}\n${blocks.join('\n\n')}\n`)

console.log(`generated ${out.replace(`${root}/`, '')} from ${documents.length} schema(s)`)
