<script setup lang="ts">
import { computed } from 'vue'

import type { ImpactFinding, ImpactReport, LintFinding } from '@/api/types'

/**
 * What this edit would cost, before it is committed.
 *
 * The hard requirement from doc 12: "removing this zone would invalidate 12 cards and 3
 * saved matches", shown *before* the change is saved, with the list. So every finding here
 * carries its evidence — card codes, deck names, the places in the system that still name
 * the thing being removed — and the save button below it is never disabled, because a
 * designer who wants to do it anyway is allowed to.
 */
const props = defineProps<{
  report: ImpactReport | null
  lint: LintFinding[]
  checking: boolean
  dirty: boolean
  violations: { pointer: string; message: string }[]
}>()

const glyph = { error: '◆', warning: '▲', info: '●' } as const

/** Only the collections that actually moved — the rest would be a wall of empty rows. */
const changes = computed(() =>
  Object.entries(props.report?.changes ?? {}).map(([collection, change]) => ({ collection, ...change })),
)

function evidence(finding: ImpactFinding): string {
  const shown = finding.evidence.join(', ')
  const hidden = finding.count - finding.evidence.length
  return hidden > 0 ? `${shown} and ${hidden} more` : shown
}
</script>

<template>
  <aside class="impact">
    <header class="head">
      <h2 class="label">Impact</h2>
      <span v-if="checking" class="mono muted">checking…</span>
    </header>

    <div v-if="violations.length" class="panel error">
      <h3 class="label">Refused</h3>
      <p v-for="violation in violations" :key="violation.pointer + violation.message" class="line">
        <span class="mono pointer">{{ violation.pointer }}</span>
        {{ violation.message }}
      </p>
    </div>

    <p v-if="!dirty && report === null" class="muted note">
      Nothing changed yet. Edit anything and this panel says what it would cost — the cards it would
      invalidate, the decks it would make illegal, the matches that would stop reproducing.
    </p>

    <template v-else-if="report">
      <div class="panel" :class="report.compiles ? (report.findings.length ? 'warn' : 'ok') : 'error'">
        <h3 class="label">
          {{
            !report.compiles
              ? 'Would not compile'
              : report.findings.length === 0
                ? 'Nothing breaks'
                : `${report.findings.length} consequence(s)`
          }}
        </h3>
        <p v-if="!report.compiles" class="line">
          {{ report.error?.message ?? 'the compiler refused this system' }}
        </p>
        <p v-else-if="report.findings.length === 0" class="line muted">
          Every card still validates, every legal deck is still legal, and the system compiles.
        </p>
        <p class="line version mono">
          {{ report.version.from }} → {{ report.version.suggested }} ({{ report.version.classification }})
        </p>
      </div>

      <article
        v-for="(finding, index) in report.findings"
        :key="index"
        class="finding"
        :class="finding.severity"
      >
        <p class="message">
          <span class="mono glyph">{{ glyph[finding.severity] }}</span>
          {{ finding.message }}
        </p>
        <p v-if="finding.evidence.length" class="mono evidence">{{ evidence(finding) }}</p>
        <p v-if="finding.fix" class="fix">{{ finding.fix }}</p>
      </article>

      <template v-if="changes.length">
        <h3 class="label section">Changed</h3>
        <p v-for="change in changes" :key="change.collection" class="change mono">
          <span class="collection">{{ change.collection }}</span>
          <span v-if="change.added.length" class="added">+{{ change.added.join(' +') }}</span>
          <span v-if="change.removed.length" class="removed">−{{ change.removed.join(' −') }}</span>
          <span v-if="change.changed.length" class="edited">~{{ change.changed.join(' ~') }}</span>
        </p>
      </template>
    </template>

    <template v-if="lint.length">
      <h3 class="label section">Lint</h3>
      <p v-for="(finding, index) in lint.slice(0, 12)" :key="index" class="line">
        <span class="mono glyph" :class="finding.severity">{{ glyph[finding.severity] }}</span>
        {{ finding.message }}
      </p>
    </template>
  </aside>
</template>

<style scoped>
.impact {
  width: 318px;
  flex: 0 0 318px;
  background: var(--surface-3);
  border-left: 1px solid var(--border-subtle);
  padding: var(--gap-6);
  overflow: auto;
}

.head {
  display: flex;
  align-items: baseline;
  gap: var(--gap-4);
  margin-bottom: var(--gap-5);
}

.head h2 {
  margin: 0;
}

.note {
  font-size: 11px;
  line-height: 1.5;
}

.panel {
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-control);
  padding: var(--gap-5);
  margin-bottom: var(--gap-5);
}

.panel.ok {
  background: var(--ok-surface);
  border-color: var(--ok-border);
}

.panel.warn {
  background: var(--warn-surface);
  border-color: var(--warn-border);
}

.panel.error {
  background: var(--error-surface);
  border-color: var(--error-border);
}

.panel h3 {
  margin: 0 0 var(--gap-3);
}

.line {
  margin: var(--gap-3) 0 0;
  font-size: 11px;
  color: var(--text-2);
  line-height: 1.45;
}

.version {
  margin-top: var(--gap-4);
  font-size: 10px;
  color: var(--text-3);
}

.pointer {
  color: var(--error-text);
  font-size: 10px;
  margin-right: var(--gap-3);
}

.finding {
  border-left: 2px solid var(--border-strong);
  padding: 0 0 var(--gap-4) var(--gap-4);
  margin-bottom: var(--gap-4);
}

.finding.error {
  border-left-color: var(--error);
}

.finding.warning {
  border-left-color: var(--warn);
}

.finding.info {
  border-left-color: var(--info);
}

.message {
  margin: 0;
  font-size: 11.5px;
  color: var(--text-1);
}

.glyph {
  font-size: 9px;
  margin-right: var(--gap-2);
}

.glyph.error {
  color: var(--error);
}

.glyph.warning {
  color: var(--warn-text);
}

.finding.error .glyph {
  color: var(--error);
}

.finding.warning .glyph {
  color: var(--warn-text);
}

.finding.info .glyph {
  color: var(--info-text);
}

.evidence {
  margin: var(--gap-3) 0 0;
  font-size: 9.5px;
  color: var(--text-3);
  line-height: 1.5;
  word-break: break-word;
}

.fix {
  margin: var(--gap-3) 0 0;
  font-size: 10.5px;
  color: var(--text-3);
  font-style: italic;
}

.section {
  margin: var(--gap-7) 0 var(--gap-4);
}

.change {
  margin: 0 0 var(--gap-3);
  font-size: 9.5px;
  display: flex;
  flex-wrap: wrap;
  gap: var(--gap-3);
}

.collection {
  color: var(--text-3);
  min-width: 74px;
}

.added {
  color: var(--ok-text);
}

.removed {
  color: var(--error-text);
}

.edited {
  color: var(--info-text);
}
</style>
