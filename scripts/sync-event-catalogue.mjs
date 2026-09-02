#!/usr/bin/env node
/**
 * Rewrites doc 06's core event catalogue from the kernel's own table.
 *
 * The catalogue is not decoration: trigger filters read these payload fields by name, and a
 * card that says "when a card enters play" is comparing `$event.to` against `"play"`. If the
 * doc and `EventCatalogue::EVENTS` disagree, the doc is teaching authors to write filters
 * that never match. So the doc is generated, and CI checks it is current.
 */
import { execFileSync } from 'node:child_process'
import { readFile, writeFile } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const doc = join(root, 'docs/06-effect-dsl.md')

const START = '<!-- generated: event-catalogue -->'
const END = '<!-- /generated: event-catalogue -->'

// Read the table out of PHP rather than parsing the source: the constant is the truth, and
// a regex over it would be a second, worse parser.
const events = JSON.parse(
  execFileSync('php', [
    '-r',
    `spl_autoload_register(function($c){ $p = "${root}/packages/kernel/src/" . str_replace(["Gmd\\\\Kernel\\\\","\\\\"],["","/"],$c) . ".php"; if (file_exists($p)) require $p; });` +
      'echo json_encode(Gmd\\Kernel\\Event\\EventCatalogue::EVENTS);',
  ]).toString(),
)

const rows = Object.entries(events)
  .map(([type, keys]) => `| \`${type}\` | ${keys.length ? keys.map((k) => `\`${k}\``).join(', ') : '—'} |`)
  .join('\n')

const table = `${START}

| Event | \`$event.*\` payload |
|---|---|
${rows}

${END}`

const source = await readFile(doc, 'utf8')
const before = source.indexOf(START)
const after = source.indexOf(END)
if (before === -1 || after === -1) throw new Error(`${doc} has no generated event-catalogue block`)

await writeFile(doc, source.slice(0, before) + table + source.slice(after + END.length))
console.log(`synced ${Object.keys(events).length} events into docs/06-effect-dsl.md`)
