<script setup lang="ts">
import { computed, onMounted } from 'vue'

import CardInPlay from '@/components/table/CardInPlay.vue'
import type { LegalAction, ViewCard } from '@/api/types'
import { useGameStore } from '@/stores/game'
import { useMatchStore } from '@/stores/match'

const props = defineProps<{ matchId: string }>()

const match = useMatchStore()
const games = useGameStore()

onMounted(() => void match.open(props.matchId, 'p0'))

const zones = computed(() => match.view?.zones ?? {})

const opponent = computed(() => (match.side === 'p0' ? 'p1' : 'p0'))

/** Every seat at the table, in seat order — one row each where the layout says `$each`. */
const seats = computed(() => (match.view?.players ?? []).map((player) => player.side))

/** The engine-controlled side, if this game has one. A duel has none and asks for none. */
const adversary = computed(() => Object.keys(match.view?.adversaries ?? {})[0] ?? null)

/**
 * Which sides a declared row is drawn for.
 *
 * `$each` is what makes a cooperative board grow with the table: one engagement row per
 * Watcher, four at four players, without the layout knowing how many there will be. The
 * duel selectors are unchanged, so Emberfall draws exactly what it drew before.
 */
function sidesFor(player: string): string[] {
  if (player === '$each') return seats.value
  if (player === '$you') return [match.side]
  if (player === '$shared') return ['shared']
  if (player === '$adversary') return adversary.value === null ? [] : [adversary.value]
  if (player === '$opponent') return [opponent.value]
  return [player]
}

/** Board rows come from the game's own ui.board, not from a fixed picture of a duel. */
const rows = computed(() => {
  const declared = games.compiled?.ui?.board?.rows
  if (declared === undefined || declared.length === 0) {
    return Object.keys(zones.value)
      .filter((key) => key.endsWith('.play'))
      .map((key) => ({ id: key, label: key, key, seat: null as string | null }))
  }

  return declared.flatMap((row) => {
    const sides = sidesFor(row.player)
    const base = row.label ?? row.id.replace(/-/g, ' ')

    return sides.map((side) => ({
      id: `${row.id}:${side}`,
      // The seat is only named where a row repeats; labelling "Engaged p0" at a solo table
      // says nothing the player did not already know.
      label: sides.length > 1 ? `${base} · ${side}` : base,
      key: `${side}.${row.zone}`,
      seat: sides.length > 1 ? side : null,
    }))
  })
})

/**
 * The villain and its scheme, read off the anchors.
 *
 * The board finds them the same way a card that says "damage the villain" does — through
 * the anchor map the scenario filled in — so nothing here knows the word "Warden".
 */
const anchored = computed(() => {
  const anchors = adversary.value === null ? {} : (match.view?.adversaries?.[adversary.value]?.anchors ?? {})

  return Object.entries(anchors)
    .map(([anchor, id]) => {
      for (const cards of Object.values(zones.value)) {
        const card = cards.find((candidate) => candidate.id === id)
        if (card !== undefined) return { anchor, card }
      }
      return null
    })
    .filter((entry): entry is { anchor: string; card: ViewCard } => entry !== null)
})

/**
 * A counter shown against the attribute that ends the game when it reaches it.
 *
 * Damage against health, threat against threshold: both are "this many out of that many,
 * and when it fills, something happens". Which pairs with which is read from the card, so
 * a game with a third such track gets it drawn without the client being told.
 */
function track(card: ViewCard): { counter: string; value: number; limit: number } | null {
  const pairs: [string, string][] = [
    ['damage', 'health'],
    ['threat', 'threshold'],
  ]

  for (const [counter, attribute] of pairs) {
    const limit = Number(card.attributes?.[attribute] ?? NaN)
    if (!Number.isNaN(limit)) {
      return { counter, value: card.counters?.[counter] ?? 0, limit }
    }
  }
  return null
}
const hand = computed(() => zones.value[`${match.side}.hand`] ?? [])

const phases = computed(() => games.compiled?.phases ?? [])

/** Which legal action, if any, plays this card — the client asks, it never decides. */
function actionFor(id: string): LegalAction | undefined {
  return match.legalActions.find((action) => Object.values(action.params).includes(id))
}

const passAction = computed(() => match.legalActions.find((action) => action.actionId === 'pass'))
const realActions = computed(() => match.legalActions.filter((action) => action.actionId !== 'pass'))

/** Newest first, because the question at the table is always "what just happened". */
const log = computed(() => [...(match.view?.log ?? [])].reverse())

const short = computed(() => match.stateHash.replace(/^sha256:/, '').slice(0, 8))

async function copyHash(): Promise<void> {
  await navigator.clipboard?.writeText(match.stateHash).catch(() => undefined)
}

/**
 * One readable line per event.
 *
 * Deliberately generic: an event catalogue is game data, and a switch over Emberfall's
 * event types here would be the first piece of game-specific code in the client. Names are
 * resolved where the card is known and the id is shown where it is not, which is honest
 * about what the viewer is allowed to see.
 */
function describe(entry: { type: string; payload: Record<string, unknown> }): string {
  return Object.entries(entry.payload)
    .filter(([, value]) => value !== null && typeof value !== 'object')
    .map(([key, value]) => `${key} ${name(String(value))}`)
    .join(' · ')
}

function name(value: string): string {
  if (!value.startsWith('i-')) return value

  for (const cards of Object.values(zones.value)) {
    const found = cards.find((card) => card.id === value)
    if (found?.name != null) return found.name
  }
  return value
}
</script>

<template>
  <div v-if="match.view" class="table">
    <header class="rail">
      <span class="round mono">Round {{ match.round }}</span>
      <div class="phases">
        <div
          v-for="phase in phases"
          :key="phase.id"
          class="phase"
          :class="{ on: phase.id === match.view.phase }"
        >
          <span class="phase-name">{{ phase.name }}</span>
          <span class="steps mono">
            <span
              v-for="stepEntry in phase.steps"
              :key="stepEntry.id"
              :class="{ current: phase.id === match.view.phase && stepEntry.id === match.view.step }"
              >{{ stepEntry.name }}</span
            >
          </span>
        </div>
      </div>
      <div class="whose">
        <span class="label">Waiting on</span>
        <span class="mono">{{ match.toAct ?? match.view.activeSide }}</span>
        <!-- At a hotseat table the board follows the seat that has to act, so it has to say
             whose cards are on screen — otherwise a player is looking at someone else's
             hand without being told the chair moved. -->
        <span v-if="match.mode === 'hotseat'" class="mono seat-note" data-seat-note>
          showing {{ match.side }}
        </span>
      </div>

      <!-- The state hash is on screen because a playtest note that carries one can be
           reproduced exactly, and one that does not usually cannot. -->
      <button
        class="hash mono"
        :data-state-hash="match.stateHash"
        :title="`${match.stateHash} · version ${match.version} — click to copy`"
        @click="copyHash"
      >
        {{ short }}
      </button>
    </header>

    <div class="middle">
      <main class="board">
        <!-- The adversary's anchors, above the board they are pressing on. A duel has no
             anchors and gets no strip. -->
        <section v-if="anchored.length > 0" class="anchors" data-anchors>
          <article v-for="entry in anchored" :key="entry.anchor" class="anchor" :data-anchor="entry.anchor">
            <span class="label">{{ entry.anchor }}</span>
            <span class="anchor-name">{{ entry.card.name }}</span>
            <template v-if="track(entry.card)">
              <div class="meter" :data-counter="track(entry.card)!.counter">
                <div
                  class="fill"
                  :class="track(entry.card)!.counter"
                  :style="{
                    width: `${Math.min(100, (track(entry.card)!.value / Math.max(1, track(entry.card)!.limit)) * 100)}%`,
                  }"
                />
              </div>
              <span class="mono meter-text">
                {{ track(entry.card)!.counter }} {{ track(entry.card)!.value }}/{{ track(entry.card)!.limit }}
              </span>
            </template>
          </article>
        </section>

        <section v-for="row in rows" :key="row.id" class="row" :data-zone="row.key">
          <header class="row-head">
            <span class="label">{{ row.label }}</span>
            <span class="mono muted">{{ (zones[row.key] ?? []).length }}</span>
          </header>
          <div class="strip">
            <CardInPlay
              v-for="card in zones[row.key] ?? []"
              :key="card.id"
              :card="card"
              :targetable="match.targetable.has(card.id)"
              :dimmed="match.pendingChoice !== null && !match.targetable.has(card.id)"
              @click="actionFor(card.id) && match.act(actionFor(card.id)!)"
            />
            <p v-if="(zones[row.key] ?? []).length === 0" class="muted empty">empty</p>
          </div>
        </section>
      </main>

      <!-- The choice prompt is docked, not modal: the board it is asking about has to stay
         visible, which is the whole reason the design refuses a dialog here. -->
      <div v-if="match.pendingChoice" class="prompt">
        <span class="mono id">{{ match.pendingChoice.id }}</span>
        <span class="text">{{ match.pendingChoice.prompt }}</span>
        <span class="spacer" />
        <button
          v-for="candidate in match.pendingChoice.options?.cards ?? []"
          :key="candidate"
          class="choice"
          @click="match.choose([candidate])"
        >
          {{ match.card(`${match.side}.play`, candidate)?.name ?? candidate }}
        </button>
        <button v-if="match.pendingChoice.optional" class="choice ghost" @click="match.choose([])">
          Decline
        </button>
      </div>

      <!-- The log is the server's account of what happened, not the client's reconstruction
         of it: every line here came down with a response. -->
      <aside class="log">
        <h2 class="label">Log</h2>
        <ol>
          <li v-for="entry in log" :key="entry.seq" data-log-entry>
            <span class="mono seq">{{ entry.seq }}</span>
            <span class="mono type">{{ entry.type }}</span>
            <span class="muted detail">{{ describe(entry) }}</span>
          </li>
        </ol>
      </aside>
    </div>

    <footer class="dock">
      <div class="hand">
        <CardInPlay
          v-for="(card, index) in hand"
          :key="card.id"
          :card="card"
          :dimmed="actionFor(card.id) === undefined"
          :style="{ transform: `rotate(${(index - (hand.length - 1) / 2) * 3}deg)` }"
          @click="actionFor(card.id) && match.act(actionFor(card.id)!)"
        />
      </div>

      <div class="actions">
        <span class="resources mono">
          <template
            v-for="(amount, id) in match.view.players.find((p) => p.side === match.side)?.resources ?? {}"
            :key="id"
          >
            {{ id }} {{ amount }}
          </template>
        </span>
        <button
          v-for="(action, index) in realActions.slice(0, 6)"
          :key="action.key"
          class="action"
          :data-action="action.actionId"
          @click="match.act(action)"
        >
          <span class="mono key">{{ index + 1 }}</span> {{ action.label }}
        </button>
        <button v-if="passAction" class="action" data-action="pass" @click="match.act(passAction)">
          Pass
        </button>
        <button class="action ghost" :disabled="match.actionCount === 0" @click="match.undo()">Undo</button>
      </div>

      <p v-if="match.problem" class="problem">{{ match.problem }}</p>
      <p v-if="match.view.result" class="result">
        {{ match.view.result.draw ? 'Draw' : `${match.view.result.winners.join(' + ')} wins` }}
        <span class="muted">({{ match.view.result.reason }}, {{ match.view.result.rounds }} rounds)</span>
      </p>
    </footer>
  </div>
  <p v-else class="loading muted">Loading the table…</p>
</template>

<style scoped>
.seat-note {
  display: block;
  font-size: 9px;
  color: var(--text-4);
}

.anchors {
  display: flex;
  gap: var(--gap-4);
  padding: var(--gap-4) 0 var(--gap-5);
  border-bottom: 1px solid var(--border-subtle);
  margin-bottom: var(--gap-4);
}

.anchor {
  flex: 1 1 0;
  min-width: 0;
  background: var(--surface-2);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-panel, 6px);
  padding: var(--gap-3) var(--gap-4);
}

.anchor-name {
  display: block;
  font-weight: 600;
  margin: 2px 0 var(--gap-3);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.meter {
  height: 4px;
  border-radius: 2px;
  background: var(--surface-0);
  overflow: hidden;
}

.meter .fill {
  height: 100%;
  background: var(--text-4);
}

/* Damage and threat are the two clocks in this format and read as different dangers: one
   is the villain dying, the other is the table losing. */
.meter .fill.damage {
  background: var(--danger, #b4453c);
}

.meter .fill.threat {
  background: var(--accent);
}

.meter-text {
  display: block;
  margin-top: 3px;
  font-size: 9px;
  color: var(--text-4);
}

.table {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--surface-1);
}

.middle {
  display: flex;
  flex: 1;
  min-height: 0;
}

.hash {
  display: flex;
  align-items: center;
  padding: 0 var(--gap-5);
  background: none;
  border: 0;
  border-left: 1px solid var(--border-default);
  color: var(--text-4);
  font-size: 10px;
  cursor: pointer;
}

.hash:hover {
  color: var(--text-2);
}

.log {
  width: 306px;
  flex: 0 0 306px;
  background: var(--surface-3);
  border-left: 1px solid var(--border-subtle);
  padding: var(--gap-5);
  overflow: auto;
}

.log ol {
  list-style: none;
  margin: var(--gap-4) 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.log li {
  display: grid;
  grid-template-columns: 26px auto;
  gap: var(--gap-3);
  padding: 2px 0;
  border-bottom: 1px solid var(--border-faint);
  font-size: 10.5px;
}

.log .seq {
  color: var(--text-4);
  text-align: right;
}

.log .type {
  color: var(--info-text);
}

.log .detail {
  grid-column: 2;
  font-size: 10px;
}

.rail {
  display: flex;
  align-items: stretch;
  height: 46px;
  background: var(--surface-4);
  border-bottom: 1px solid var(--border-default);
  flex: 0 0 auto;
}

.round {
  display: flex;
  align-items: center;
  width: 86px;
  padding: 0 var(--gap-5);
  font-size: 11px;
  border-right: 1px solid var(--border-default);
}

.phases {
  display: flex;
  flex: 1;
}

.phase {
  padding: var(--gap-3) var(--gap-6);
  border-right: 1px solid var(--border-faint);
  opacity: 0.5;
  border-bottom: 2px solid transparent;
}

.phase.on {
  opacity: 1;
  background: var(--surface-5);
  border-bottom-color: var(--accent);
}

.phase-name {
  font-size: 11px;
  font-weight: 600;
}

.steps {
  display: flex;
  gap: var(--gap-4);
  font-size: 8.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-4);
}

.steps .current {
  color: var(--text-1);
  font-weight: 600;
}

.whose {
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 2px;
  padding: 0 var(--gap-6);
  background: #191316;
  border-left: 1px solid var(--border-default);
}

.board {
  flex: 1;
  min-width: 0;
  overflow: auto;
  padding: var(--gap-6);
  display: flex;
  flex-direction: column;
  gap: var(--gap-6);
}

.row-head {
  display: flex;
  gap: var(--gap-4);
  align-items: baseline;
  margin-bottom: var(--gap-3);
}

.strip {
  display: flex;
  gap: var(--gap-5);
  min-height: 112px;
  align-items: flex-start;
  /* A row a designer has filled with a dozen characters is a normal late board, not an
     edge case. It scrolls; the page never does. */
  overflow-x: auto;
  overflow-y: hidden;
  padding-bottom: var(--gap-3);
}

/* An exhausted card is rotated, and a rotation does not change the box the layout
   reserves for it, so it would overlap its neighbour without this. */
.strip :deep(.card.exhausted) {
  margin: 0 10px;
}

.empty {
  font-size: 10px;
  align-self: center;
}

.prompt {
  display: flex;
  align-items: center;
  gap: var(--gap-5);
  padding: var(--gap-5) var(--gap-6);
  background: #1a1512;
  border-left: 3px solid var(--accent);
  border-top: 1px solid var(--border-default);
}

.prompt .id {
  font-size: 9.5px;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--text-3);
}

.spacer {
  flex: 1;
}

.choice {
  height: var(--control);
  padding: 0 var(--gap-5);
  background: var(--surface-6);
  border: 1px solid var(--border-strong);
  border-radius: var(--radius-control);
  color: var(--text-1);
  cursor: pointer;
}

.dock {
  border-top: 1px solid var(--border-default);
  background: var(--surface-3);
  padding: var(--gap-5) var(--gap-6);
  flex: 0 0 auto;
}

.hand {
  display: flex;
  gap: var(--gap-4);
  justify-content: center;
  min-height: 112px;
}

.actions {
  display: flex;
  align-items: center;
  gap: var(--gap-4);
  margin-top: var(--gap-5);
}

.resources {
  font-size: 11px;
  color: var(--text-2);
  margin-right: var(--gap-5);
}

.action {
  height: var(--control);
  padding: 0 var(--gap-5);
  background: var(--surface-5);
  border: 1px solid var(--border-default);
  border-radius: var(--radius-control);
  color: var(--text-1);
  cursor: pointer;
  font-size: 11.5px;
}

.action:disabled {
  opacity: 0.4;
  cursor: default;
}

.action .key {
  font-size: 9px;
  color: var(--text-4);
  margin-right: 3px;
}

.ghost {
  background: none;
}

.problem {
  margin: var(--gap-4) 0 0;
  color: var(--warn-text);
  font-size: 11px;
}

.result {
  margin: var(--gap-4) 0 0;
  color: var(--ok-text);
  font-size: 13px;
  font-weight: 600;
}

.loading {
  padding: var(--gap-8);
}
</style>
