/**
 * What each collection of the system document is made of, as data.
 *
 * Ten tabs of the system editor are a list of things with ids and a form of fields, and the
 * only thing that differs between them is which fields. Describing that here rather than
 * writing ten forms keeps them consistent — and adding a field to the editor is adding a row
 * to a table, which is the same bargain the card editor already makes with its compiled
 * descriptors.
 *
 * The vocabulary of each field comes from the document itself: an action's windows are the
 * steps this game declares, a card type's `playableTo` is this game's zones. That is what
 * stops the editor from having opinions about what a game contains.
 */

/** A list whose members come from elsewhere in the document rather than from this table. */
export type OptionSource = 'zones' | 'cardTypes' | 'steps' | 'resources' | 'counters' | 'keywords'

export interface FieldSpec {
  /** A dotted path inside the entry: `window.type`, `outcome.loser`. */
  key: string
  label: string
  type: 'text' | 'number' | 'textarea' | 'select' | 'checkbox' | 'tags'
  options?: { value: string; label: string }[]
  source?: OptionSource
  hint?: string
  placeholder?: string
}

export interface TabSpec {
  id: string
  label: string
  /** Dotted path to the collection in the system document. */
  path: string
  /** What one of them is called, on the add button and in the impact panel. */
  noun: string
  idStem: string
  /** Only where order is part of the rules. */
  reorderable?: boolean
  fields: FieldSpec[]
  /** Keys holding effect or expression trees, drawn as nodes and edited as JSON. */
  scripts?: string[]
  blank: Record<string, unknown>
}

export const COLLECTION_TABS: TabSpec[] = [
  {
    id: 'zones',
    label: 'Zones',
    path: 'zones',
    noun: 'zone',
    idStem: 'zone',
    blank: { id: 'zone', name: 'Zone', scope: 'player', visibility: 'public', ordered: false },
    fields: [
      { key: 'id', label: 'Id', type: 'text', hint: 'lowercase, referenced by every effect' },
      { key: 'name', label: 'Name', type: 'text' },
      {
        key: 'scope',
        label: 'Scope',
        type: 'select',
        options: [
          { value: 'player', label: 'One per player' },
          { value: 'shared', label: 'Shared' },
          { value: 'adversary', label: 'An adversary’s' },
        ],
      },
      {
        key: 'visibility',
        label: 'Visibility',
        type: 'select',
        options: [
          { value: 'none', label: 'Nobody' },
          { value: 'owner', label: 'Its owner' },
          { value: 'controller', label: 'Its controller' },
          { value: 'public', label: 'Everyone' },
        ],
      },
      { key: 'ordered', label: 'Ordered', type: 'checkbox', placeholder: 'position matters' },
      { key: 'faceDown', label: 'Face down', type: 'checkbox', placeholder: 'cards sit face down' },
      {
        key: 'supportsAttachments',
        label: 'Attachments',
        type: 'checkbox',
        placeholder: 'cards may be attached here',
      },
      { key: 'maxSize', label: 'Max size', type: 'number', hint: 'empty = no limit' },
    ],
  },
  {
    id: 'resources',
    label: 'Resources',
    path: 'resources',
    noun: 'resource',
    idStem: 'resource',
    blank: { id: 'resource', name: 'Resource', start: 0, min: 0, max: null, carryOver: false },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'name', label: 'Name', type: 'text' },
      { key: 'start', label: 'Starts at', type: 'number' },
      { key: 'min', label: 'Minimum', type: 'number' },
      { key: 'max', label: 'Maximum', type: 'number', hint: 'empty = uncapped' },
      { key: 'carryOver', label: 'Carries over', type: 'checkbox', placeholder: 'survives the round' },
      { key: 'icon', label: 'Icon', type: 'text' },
    ],
  },
  {
    id: 'counters',
    label: 'Counters',
    path: 'counters',
    noun: 'counter',
    idStem: 'counter',
    blank: { id: 'counter', name: 'Counter' },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'name', label: 'Name', type: 'text' },
      { key: 'visual', label: 'Visual', type: 'text', placeholder: 'pip-red' },
      { key: 'max', label: 'Maximum', type: 'number', hint: 'empty = uncapped' },
    ],
  },
  {
    id: 'keywords',
    label: 'Keywords',
    path: 'keywords',
    noun: 'keyword',
    idStem: 'keyword',
    blank: { id: 'keyword', name: 'Keyword', reminder: '' },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'name', label: 'Name', type: 'text' },
      {
        key: 'reminder',
        label: 'Reminder text',
        type: 'textarea',
        hint: 'what the card prints in brackets',
      },
    ],
    scripts: ['parameters', 'grants'],
  },
  {
    id: 'actions',
    label: 'Actions',
    path: 'actions',
    noun: 'action',
    idStem: 'action',
    blank: { id: 'action', name: 'Action', windows: [], effect: [] },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'name', label: 'Name', type: 'text' },
      {
        key: 'windows',
        label: 'Windows',
        type: 'tags',
        source: 'steps',
        hint: 'the steps this may be taken in',
      },
      { key: 'text', label: 'Rules text', type: 'textarea' },
      { key: 'limit.perRound', label: 'Limit per round', type: 'number' },
      { key: 'limit.perPhase', label: 'Limit per phase', type: 'number' },
    ],
    scripts: ['targets', 'requirements', 'cost', 'effect', 'emits'],
  },
  {
    id: 'stateChecks',
    label: 'State Checks',
    path: 'stateChecks',
    noun: 'check',
    idStem: 'check',
    blank: { id: 'check', when: { op: 'eq', left: 1, right: 1 }, then: [] },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'scope.zone', label: 'In zone', type: 'select', source: 'zones' },
      { key: 'scope.players', label: 'For players', type: 'text', placeholder: 'all' },
      { key: 'phase', label: 'Only in phase', type: 'text' },
      { key: 'step', label: 'Only in step', type: 'text' },
    ],
    scripts: ['scope', 'when', 'then'],
  },
  {
    id: 'winConditions',
    label: 'Win Conditions',
    path: 'winConditions',
    noun: 'condition',
    idStem: 'condition',
    blank: {
      id: 'condition',
      check: { op: 'gt', left: { op: 'round' }, right: 25 },
      outcome: { draw: true },
    },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'scope.players', label: 'For players', type: 'text', placeholder: 'all' },
      { key: 'trigger', label: 'Trigger', type: 'text', placeholder: 'on_draw_from_empty' },
      { key: 'outcome.winner', label: 'Winner', type: 'text', placeholder: '$player' },
      { key: 'outcome.loser', label: 'Loser', type: 'text', placeholder: '$player' },
      { key: 'outcome.draw', label: 'Draw', type: 'checkbox', placeholder: 'nobody wins' },
      { key: 'outcome.allWin', label: 'All win', type: 'checkbox', placeholder: 'cooperative victory' },
      { key: 'outcome.allLose', label: 'All lose', type: 'checkbox', placeholder: 'cooperative defeat' },
      { key: 'text', label: 'Rulebook line', type: 'textarea' },
    ],
    scripts: ['check'],
  },
  {
    id: 'board',
    label: 'Board Layout',
    path: 'ui.board.rows',
    noun: 'row',
    idStem: 'row',
    reorderable: true,
    blank: { id: 'row', zone: '', player: '$you', label: 'Row' },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'zone', label: 'Zone', type: 'select', source: 'zones' },
      {
        key: 'player',
        label: 'Whose',
        type: 'select',
        options: [
          { value: '$you', label: 'Yours' },
          { value: '$opponent', label: 'The opponent’s' },
          { value: '$shared', label: 'Shared' },
        ],
      },
      { key: 'label', label: 'Label', type: 'text' },
      { key: 'collapsed', label: 'Collapsed', type: 'checkbox', placeholder: 'starts collapsed' },
    ],
  },
  {
    id: 'rules',
    label: 'Rules Text',
    path: 'rulesText.sections',
    noun: 'section',
    idStem: 'section',
    reorderable: true,
    blank: { id: 'section', title: 'Section', body: '' },
    fields: [
      { key: 'id', label: 'Id', type: 'text' },
      { key: 'title', label: 'Title', type: 'text' },
      { key: 'body', label: 'Body', type: 'textarea' },
    ],
  },
]

/** The tabs, in the order the brief lists them, including the ones with bespoke editors. */
export const TABS = [
  { id: 'game', label: 'Game' },
  { id: 'zones', label: 'Zones' },
  { id: 'round', label: 'Phases & Steps' },
  { id: 'resources', label: 'Resources' },
  { id: 'counters', label: 'Counters' },
  { id: 'cardTypes', label: 'Card Types' },
  { id: 'keywords', label: 'Keywords' },
  { id: 'vocabularies', label: 'Vocabularies' },
  { id: 'actions', label: 'Actions' },
  { id: 'stateChecks', label: 'State Checks' },
  { id: 'winConditions', label: 'Win Conditions' },
  { id: 'deckbuilding', label: 'Deckbuilding' },
  { id: 'board', label: 'Board Layout' },
  { id: 'rules', label: 'Rules Text' },
] as const
