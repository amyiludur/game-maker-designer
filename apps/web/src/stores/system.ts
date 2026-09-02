import { defineStore } from 'pinia'
import { computed, ref, toRaw } from 'vue'

import { ApiError_, api } from '@/api/client'
import type { ImpactReport, LintFinding } from '@/api/types'
import { useGameStore } from '@/stores/game'

/** Anything in the system document that has an id: a zone, a card type, an action, a phase. */
export type SystemEntity = { id: string; name?: string } & Record<string, unknown>

/**
 * The game system document, under edit.
 *
 * One document, held whole. Every tab of the system editor is a view onto a slice of it, and
 * the JSON view is the same object printed — which is what makes the toggle lossless rather
 * than a second editor that has to agree with the first.
 *
 * Nothing is written until `save()`. `check()` asks the server what the edit would cost
 * first (doc 12: impact warnings are shown *before* the change is committed), and the answer
 * is evidence — the cards, the decks, the places in the system that still name the thing
 * being removed.
 */
export const useSystemStore = defineStore('system', () => {
  const slug = ref<string | null>(null)
  const semver = ref<string | null>(null)
  const status = ref<'draft' | 'published' | 'archived'>('draft')

  const document_ = ref<Record<string, unknown>>({})
  /** The document as last agreed with the server, for the only honest definition of dirty. */
  const committed = ref('{}')

  const report = ref<ImpactReport | null>(null)
  const violations = ref<{ pointer: string; message: string }[]>([])
  const lint = ref<LintFinding[]>([])

  const loading = ref(false)
  const saving = ref(false)
  const checking = ref(false)

  const dirty = computed(() => JSON.stringify(document_.value) !== committed.value)
  const editable = computed(() => status.value === 'draft')

  /** Errors first: the panel is read top-down and an error is what stops a save. */
  const findings = computed(() => {
    const order = { error: 0, warning: 1, info: 2 }
    return [...(report.value?.findings ?? [])].sort((a, b) => order[a.severity] - order[b.severity])
  })

  const breaking = computed(() => findings.value.some((finding) => finding.severity === 'error'))

  async function load(game: string): Promise<void> {
    const games = useGameStore()
    await games.load(game)

    // Asked of the API rather than read off the game store when the store has not caught up.
    // Two `games.load()` calls race on arrival — App.vue's route watcher and this one — and
    // the loser used to return here silently, leaving the editor showing an empty document
    // for a game that has one.
    const version =
      games.current?.slug === game && games.current.version != null
        ? games.current.version
        : ((await api.game(game)).version ?? null)

    if (version === null) return
    if (slug.value === game && semver.value === version.semver && dirty.value) return

    loading.value = true
    try {
      const detail = await api.version(game, version.semver)
      slug.value = game
      semver.value = detail.semver
      status.value = detail.status
      document_.value = structuredClone(detail.document)
      committed.value = JSON.stringify(detail.document)
      report.value = null
      violations.value = []
      lint.value = games.current?.slug === game ? games.lint : []
    } finally {
      loading.value = false
    }
  }

  /** A collection of the document, addressed by a dotted path: `zones`, `round.phases`. */
  function list(path: string): SystemEntity[] {
    const value = read(path)
    return Array.isArray(value) ? (value as SystemEntity[]) : []
  }

  function read(path: string): unknown {
    let node: unknown = document_.value
    for (const key of path.split('.')) {
      if (node === null || typeof node !== 'object') return undefined
      node = (node as Record<string, unknown>)[key]
    }
    return node
  }

  /**
   * Write a value at a dotted path, creating the objects along the way.
   *
   * The document is replaced rather than mutated in place so that Vue sees one change per
   * edit — a deep mutation of a nested array is exactly the shape that leaves the JSON view
   * showing yesterday's document.
   */
  function write(path: string, value: unknown): void {
    const keys = path.split('.')
    // `toRaw` first: `structuredClone` cannot clone a reactive proxy, and it throws rather
    // than degrading — which silently swallowed every edit the system editor made.
    const next = structuredClone(toRaw(document_.value))

    let node = next as Record<string, unknown>
    for (const key of keys.slice(0, -1)) {
      if (node[key] === null || typeof node[key] !== 'object') node[key] = {}
      node = node[key] as Record<string, unknown>
    }
    // `split` always yields at least one element, so the guard is for the type rather than
    // for a case that can happen.
    const leaf = keys[keys.length - 1]
    if (leaf === undefined) return

    node[leaf] = value

    document_.value = next
  }

  function add(path: string, entry: SystemEntity): void {
    write(path, [...list(path), entry])
  }

  function remove(path: string, id: string): void {
    write(
      path,
      list(path).filter((entry) => entry.id !== id),
    )
  }

  function patch(path: string, id: string, changes: Record<string, unknown>): void {
    write(
      path,
      list(path).map((entry) => (entry.id === id ? { ...entry, ...changes } : entry)),
    )
  }

  /** Reorder within a collection. Phases and steps are an order, not a set. */
  function move(path: string, from: number, to: number): void {
    const entries = [...list(path)]
    if (from < 0 || to < 0 || from >= entries.length || to >= entries.length) return
    entries.splice(to, 0, ...entries.splice(from, 1))
    write(path, entries)
  }

  /** An id nothing in this collection is using yet: `zone`, `zone-2`, `zone-3`. */
  function freeId(path: string, stem: string): string {
    const taken = new Set(list(path).map((entry) => entry.id))
    if (!taken.has(stem)) return stem
    for (let n = 2; ; n++) {
      if (!taken.has(`${stem}_${n}`)) return `${stem}_${n}`
    }
  }

  /** Replace the whole document — what the JSON view does when its text parses. */
  function replace(document: Record<string, unknown>): void {
    document_.value = document
  }

  /** What would this edit cost? Asked of the server, which has the cards and the decks. */
  async function check(): Promise<void> {
    if (slug.value === null || semver.value === null) return

    checking.value = true
    try {
      report.value = await api.impact(slug.value, semver.value, document_.value)
    } finally {
      checking.value = false
    }
  }

  /**
   * Commit the document.
   *
   * Returns false when the server refused it; `violations` then says where, by JSON Pointer,
   * so the editor can put each message on the tab that owns it.
   */
  async function save(): Promise<boolean> {
    if (slug.value === null || semver.value === null) return false

    saving.value = true
    violations.value = []
    try {
      const result = await api.saveVersion(slug.value, semver.value, document_.value)

      committed.value = JSON.stringify(document_.value)
      semver.value = result.semver
      lint.value = result.lint.findings ?? []
      report.value = null

      // The compiled bundle is what every other screen renders from, so a system change has
      // to reach the card editor without a reload.
      const games = useGameStore()
      await games.reload()

      return true
    } catch (error) {
      if (error instanceof ApiError_) {
        violations.value =
          error.violations.length > 0 ? error.violations : [{ pointer: '/', message: error.error.message }]
        return false
      }
      throw error
    } finally {
      saving.value = false
    }
  }

  /** Throw the edit away. The button that makes editing safe to start. */
  function revert(): void {
    document_.value = JSON.parse(committed.value)
    report.value = null
    violations.value = []
  }

  return {
    slug,
    semver,
    status,
    document: document_,
    report,
    findings,
    breaking,
    violations,
    lint,
    loading,
    saving,
    checking,
    dirty,
    editable,
    load,
    list,
    read,
    write,
    add,
    remove,
    patch,
    move,
    freeId,
    replace,
    check,
    save,
    revert,
  }
})
