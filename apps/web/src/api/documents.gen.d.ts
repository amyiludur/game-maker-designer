/**
 * Generated from schemas/*.json by `npm run types`. Do not edit.
 *
 * These are the authored documents — the same files the API validates against and the
 * kernel plays. The API's own envelopes are hand-written in `types.ts`, because those are
 * the shape of a response rather than the shape of a document.
 */

/* eslint-disable */

/**
 * Shared building blocks for the effect DSL: selectors, queries, expressions, effects and abilities. Referenced by game-system.schema.json and card.schema.json.
 */

export type AbilityEffectDSL = {
  [k: string]: unknown
}

/**
 * A value or predicate. Either a literal, a selector, or an {op: ...} node.
 */

export type AbilityExpression =
  AbilityLiteral | AbilitySelector | AbilityExpressionNode | AbilityExpression[]

export type AbilityLiteral = string | number | boolean | null

/**
 * A reference such as $self, $you, $opponent, $target.victim, $event.card, $each, $card, $player, $param.n
 */

export type AbilitySelector = string

export type AbilityTagFilter =
  | string[]
  | {
      any?: string[]
      all?: string[]
      none?: string[]
    }

/**
 * One card's design document. Attribute contents are additionally validated against the compiled schema for its card type.
 */

export type Card = {
  [k: string]: unknown
}

export type AbilityTargets = {
  id: string
  query: AbilityQuery
  count?:
    | AbilityExpression
    | {
        min?: AbilityExpression
        max?: AbilityExpression
      }
  optional?: boolean
  chooser?: AbilitySelector
  prompt?: string
}[]

/**
 * An ordered list of ops.
 */

export type AbilityEffect = AbilityOp[]

export type AbilityAbility = {
  [k: string]: unknown
} & {
  id: string
  kind: 'triggered' | 'activated' | 'static' | 'replacement' | 'constant'
  speed?: 'action' | 'reaction' | 'forced' | 'passive'
  trigger?: AbilityTrigger
  activeWhile?: AbilityExpression
  cost?: AbilityEffect
  requirements?: AbilityExpression[]
  targets?: AbilityTargets
  effect?: AbilityEffect
  limit?: {
    perStep?: number
    perPhase?: number
    perTurn?: number
    perRound?: number
    perGame?: number
  }
  text?: string | null
}

/**
 * An ordered list of ops.
 */

export type AbilityEffect1 = AbilityOp[]

/**
 * A named agent strategy and its configuration. Heuristic features use the same expression language as card abilities.
 */

export interface BotProfile {
  $schema?: string
  schemaVersion: string
  id?: string
  gameId?: string | null
  name: string
  strategy: 'random' | 'scripted' | 'heuristic' | 'mcts' | 'human_replay'
  config?: {
    weights?: {
      [k: string]: number
    }
    features?: {
      id: string
      expr: AbilityExpression
      description?: string
    }[]
    lookahead?: number
    randomTiebreak?: boolean
    iterations?: number
    milliseconds?: number
    determinizations?: number
    script?: {
      actionId: string
      params?: {}
    }[]
    choicePolicy?: 'first' | 'random' | 'score'
  }
}

export interface AbilityExpressionNode {
  op:
    | 'attr'
    | 'baseAttr'
    | 'counter'
    | 'resource'
    | 'count'
    | 'zone_size'
    | 'round'
    | 'phase'
    | 'step'
    | 'var'
    | 'param'
    | 'random_int'
    | 'constant'
    | 'player_count'
    | 'side_of'
    | 'face'
    | 'add'
    | 'sub'
    | 'mul'
    | 'div'
    | 'mod'
    | 'min'
    | 'max'
    | 'abs'
    | 'clamp'
    | 'if'
    | 'eq'
    | 'ne'
    | 'lt'
    | 'lte'
    | 'gt'
    | 'gte'
    | 'and'
    | 'or'
    | 'not'
    | 'has_trait'
    | 'has_keyword'
    | 'is_type'
    | 'in_zone'
    | 'controlled_by'
    | 'owned_by'
    | 'is_exhausted'
    | 'is_face_down'
    | 'is_attached'
    | 'entered_this_round'
    | 'can_enter_play'
    | 'has_permission'
    | 'has_restriction'
    | 'exists'
    | 'all'
    | 'any'
    | 'none'
    | 'can_pay'
    | 'matches'
  of?: unknown
  left?: AbilityExpression
  right?: AbilityExpression
  cond?: AbilityExpression
  then?: AbilityExpression
  else?: AbilityExpression
  query?: AbilityQuery
  match?: AbilityExpression
  attr?: string
  counter?: string
  resource?: string
  zone?: string
  player?: AbilityExpression
  card?: AbilityExpression
  trait?: string
  keyword?: string
  type?: string
  id?: string
  value?: AbilityExpression
  min?: AbilityExpression
  max?: AbilityExpression
  [k: string]: unknown
}

/**
 * Declarative selection of card instances or players.
 */

export interface AbilityQuery {
  zone?: string | string[]
  /**
   * Whose copy of a player-scoped zone to look in. Distinct from controller (who commands the card) and owner (whose deck it came from).
   */
  zonePlayer?: string
  controller?: string
  owner?: string
  face?: 'front' | 'back'
  types?: string[]
  traits?: AbilityTagFilter
  keywords?: AbilityTagFilter
  where?: AbilityExpression
  exclude?: AbilitySelector[]
  order?: {
    by?: AbilityExpression
    dir?: 'asc' | 'desc'
  }
  limit?: AbilityExpression
  players?: 'all' | 'opponents' | 'you' | 'active'
}

export interface Deck {
  $schema?: string
  schemaVersion: string
  gameId: string
  gameVersion: string
  name: string
  /**
   * Card code of the hero/identity card
   */
  identity?: string | null
  cards: {
    code: string
    count: number
  }[]
  sideboard?: {
    code: string
    count: number
  }[]
  archetype?: string
  notes?: string
}

/**
 * A named bundle of adversary cards (treacheries, minions, attachments) that scenarios include by reference. Modular sets are what let one scenario be reconfigured without rewriting it.
 */

export interface EncounterSet {
  $schema?: string
  schemaVersion: string
  code: string
  gameId: string
  name: string
  kind?: 'scenario' | 'modular' | 'nemesis' | 'difficulty'
  /**
   * For nemesis sets: the hero card code this set belongs to
   */
  matching?: string
  summary?: string
  /**
   * Card codes and how many copies the set contributes.
   */
  cards: {
    code: string
    count: number
  }[]
  design?: {
    goals?: string[]
    notes?: string
  }
}

/**
 * A complete declarative definition of a card game's rules structure.
 */

export interface GameSystem {
  $schema?: string
  schemaVersion: string
  id: string
  version: string
  name: string
  summary?: string
  players: {
    min: number
    max: number
    mode?: 'competitive' | 'cooperative' | 'solo'
    teams?: boolean
  }
  /**
   * Non-player sides controlled by the engine rather than by a person or a bot. See docs/16-cooperative-and-adversary-games.md.
   */
  adversaries?: {
    id: string
    name: string
    /**
     * An adversary executes a script; it never receives a pendingChoice.
     */
    controlledBy: 'engine'
    /**
     * Zone ids scoped to this adversary
     */
    zones?: string[]
    /**
     * Named persistent instances addressable as $adversary.<id> from any ability.
     */
    anchors?: {
      id: string
      type: string
      required?: boolean
      zone?: string
    }[]
    activation?: AbilityEffect
  }[]
  vocabularies?: {
    traits?: string[]
    rarities?: string[]
    factions?: {
      id: string
      name: string
      color?: string
      icon?: string
    }[]
    [k: string]: (string | {})[]
  }
  resources?: {
    id: string
    name: string
    start?: number
    min?: number
    max?: number | null
    perRound?: {
      gain?: number
      mode?: 'set' | 'add'
    }
    carryOver?: boolean
    icon?: string
  }[]
  counters?: {
    id: string
    name: string
    visual?: string
    max?: number | null
  }[]
  /**
   * @minItems 1
   */
  zones: [
    {
      id: string
      name: string
      scope: 'player' | 'shared' | 'adversary'
      /**
       * For scope=adversary: which adversary owns this zone
       */
      side?: string
      visibility: 'none' | 'owner' | 'controller' | 'public'
      ordered?: boolean
      faceDown?: boolean
      supportsAttachments?: boolean
      maxSize?: number | null
    },
    ...{
      id: string
      name: string
      scope: 'player' | 'shared' | 'adversary'
      /**
       * For scope=adversary: which adversary owns this zone
       */
      side?: string
      visibility: 'none' | 'owner' | 'controller' | 'public'
      ordered?: boolean
      faceDown?: boolean
      supportsAttachments?: boolean
      maxSize?: number | null
    }[]
  ]
  /**
   * @minItems 1
   */
  cardTypes: [
    {
      id: string
      name: string
      playableTo?: string[]
      attributes: {
        id: string
        name: string
        type:
          'integer' | 'decimal' | 'string' | 'text' | 'boolean' | 'enum' | 'tagList' | 'reference'
        required?: boolean
        default?: unknown
        min?: number
        max?: number
        options?: string[]
        vocabulary?: string
        /**
         * The printed value is per player; the effective value is multiplied by the player count at setup.
         */
        perPlayer?: boolean
        showOnCard?: string | boolean
        help?: string
      }[]
      modifiableAttributes?: string[]
      abilitySlots?: {
        max?: number
      }
      unique?: boolean
      isIdentity?: boolean
      doubleSided?: boolean
      controlledBy?: 'player' | 'adversary'
    },
    ...{
      id: string
      name: string
      playableTo?: string[]
      attributes: {
        id: string
        name: string
        type:
          'integer' | 'decimal' | 'string' | 'text' | 'boolean' | 'enum' | 'tagList' | 'reference'
        required?: boolean
        default?: unknown
        min?: number
        max?: number
        options?: string[]
        vocabulary?: string
        /**
         * The printed value is per player; the effective value is multiplied by the player count at setup.
         */
        perPlayer?: boolean
        showOnCard?: string | boolean
        help?: string
      }[]
      modifiableAttributes?: string[]
      abilitySlots?: {
        max?: number
      }
      unique?: boolean
      isIdentity?: boolean
      doubleSided?: boolean
      controlledBy?: 'player' | 'adversary'
    }[]
  ]
  keywords?: {
    id: string
    name: string
    reminder?: string
    parameters?: {
      id: string
      type: 'integer' | 'string' | 'enum'
      required?: boolean
      options?: string[]
    }[]
    grants?: {
      kind: 'ability' | 'permission' | 'restriction'
      ability?: AbilityAbility
      permission?: string
      restriction?: string
      value?: unknown
    }[]
  }[]
  setup?: AbilityEffect
  mulligan?: {
    enabled?: boolean
    mode?: 'full_redraw' | 'partial' | 'london'
    times?: number
  }
  round: {
    structure?: 'phased' | 'turn_based'
    firstPlayer?: {
      rule?: 'alternate' | 'rotate' | 'fixed' | 'random' | 'winner_chooses'
    }
    triggerOrdering?: 'apnap' | 'declaration'
    /**
     * @minItems 1
     */
    phases: [
      {
        id: string
        name: string
        /**
         * @minItems 1
         */
        steps: [
          {
            id: string
            name?: string
            auto?: AbilityEffect
            window?: {
              type: 'active_player' | 'alternating' | 'simultaneous' | 'defending_player'
              endOn?: 'consecutive_passes' | 'single_action' | 'all_submitted'
              actions?: string[]
              skipIfNoActions?: boolean
            }
            repeatPerPlayer?: boolean
          },
          ...{
            id: string
            name?: string
            auto?: AbilityEffect
            window?: {
              type: 'active_player' | 'alternating' | 'simultaneous' | 'defending_player'
              endOn?: 'consecutive_passes' | 'single_action' | 'all_submitted'
              actions?: string[]
              skipIfNoActions?: boolean
            }
            repeatPerPlayer?: boolean
          }[]
        ]
      },
      ...{
        id: string
        name: string
        /**
         * @minItems 1
         */
        steps: [
          {
            id: string
            name?: string
            auto?: AbilityEffect
            window?: {
              type: 'active_player' | 'alternating' | 'simultaneous' | 'defending_player'
              endOn?: 'consecutive_passes' | 'single_action' | 'all_submitted'
              actions?: string[]
              skipIfNoActions?: boolean
            }
            repeatPerPlayer?: boolean
          },
          ...{
            id: string
            name?: string
            auto?: AbilityEffect
            window?: {
              type: 'active_player' | 'alternating' | 'simultaneous' | 'defending_player'
              endOn?: 'consecutive_passes' | 'single_action' | 'all_submitted'
              actions?: string[]
              skipIfNoActions?: boolean
            }
            repeatPerPlayer?: boolean
          }[]
        ]
      }[]
    ]
  }
  actions?: {
    id: string
    name: string
    /**
     * Qualified step ids, e.g. action.main
     */
    windows: string[]
    targets?: AbilityTargets
    cost?: AbilityEffect
    requirements?: AbilityExpression[]
    effect?: AbilityEffect
    /**
     * Extra events emitted by taking this action, e.g. card.played
     */
    emits?: string[]
    limit?: {
      perStep?: number
      perPhase?: number
      perRound?: number
    }
    text?: string
  }[]
  stateChecks?: {
    id: string
    when: AbilityExpression
    scope?: {
      zone?: string
      types?: string[]
      players?: string
    }
    phase?: string
    step?: string
    then: AbilityEffect
  }[]
  /**
   * @minItems 1
   */
  winConditions: [
    {
      id: string
      check: AbilityExpression
      scope?: {
        players?: string
      }
      trigger?: string
      outcome: {
        winner?: string
        loser?: string
        draw?: boolean
        /**
         * Every player wins together (cooperative victory)
         */
        allWin?: boolean
        /**
         * Every player loses together (cooperative defeat)
         */
        allLose?: boolean
        /**
         * Selector for a player who is out while the game continues for the others
         */
        eliminate?: string
      }
      text?: string
    },
    ...{
      id: string
      check: AbilityExpression
      scope?: {
        players?: string
      }
      trigger?: string
      outcome: {
        winner?: string
        loser?: string
        draw?: boolean
        /**
         * Every player wins together (cooperative victory)
         */
        allWin?: boolean
        /**
         * Every player loses together (cooperative defeat)
         */
        allLose?: boolean
        /**
         * Selector for a player who is out while the game continues for the others
         */
        eliminate?: string
      }
      text?: string
    }[]
  ]
  deckbuilding?: {
    deckSize: {
      min: number
      max?: number | null
    }
    maxCopies?: number
    identity?: {
      type?: string
      count?: number
      zone?: string
    }
    sideboard?: {
      min?: number
      max?: number
    } | null
    constraints?: {
      id: string
      rule: AbilityExpression
      message: string
      severity?: 'error' | 'warning'
    }[]
  }
  /**
   * How an adversary's side is assembled. The cooperative counterpart of deckbuilding.
   */
  scenarioBuilding?: {
    requires?: {
      anchor: string
      from?: string
    }[]
    encounterSets?: {
      required?: string[]
      modular?: {
        min?: number
        max?: number
      }
    }
    perHeroAdditions?: {
      from: string
      matching?: string
      into: string
    }[]
    difficulties?: {
      id: string
      name: string
      encounterSets?: string[]
    }[]
  }
  ui?: {
    board?: {
      layout?: string
      rows?: {
        id: string
        zone: string
        player?: string
        collapsed?: boolean
        label?: string
      }[]
      docks?: {
        [k: string]: string
      }
    }
    cardTemplate?: string
    theme?: {
      [k: string]: string
    }
  }
  rulesText?: {
    sections?: {
      id: string
      title: string
      body: string
    }[]
    generate?: string[]
  }
}

export interface AbilityOp {
  op:
    | 'draw'
    | 'move_card'
    | 'move_cards'
    | 'discard'
    | 'choose_and_discard'
    | 'destroy'
    | 'put_into_play'
    | 'create_token'
    | 'shuffle'
    | 'search'
    | 'reveal'
    | 'look_at'
    | 'attach'
    | 'detach'
    | 'reveal_encounter'
    | 'flip_card'
    | 'replace_card'
    | 'engage'
    | 'run_activation'
    | 'exhaust'
    | 'ready'
    | 'ready_all'
    | 'exhaust_all'
    | 'add_counter'
    | 'remove_counter'
    | 'deal_damage'
    | 'heal'
    | 'gain_resource'
    | 'pay_resource'
    | 'gain_control'
    | 'modify'
    | 'modify_event'
    | 'grant_ability'
    | 'set_flag'
    | 'expire_modifiers'
    | 'enforce_hand_size'
    | 'sequence'
    | 'if'
    | 'for_each'
    | 'for_each_player'
    | 'repeat'
    | 'choose_one'
    | 'choose_cards'
    | 'choose_number'
    | 'prompt_yes_no'
    | 'set_var'
    | 'set_first_player'
    | 'resolve_combat'
    | 'declare_attack'
    | 'declare_block'
    | 'end_step'
    | 'end_phase'
    | 'extra_turn'
    | 'win_game'
    | 'lose_game'
    | 'draw_game'
    | 'custom'
  do?: AbilityEffect
  then?: AbilityEffect
  else?: AbilityEffect
  cond?: AbilityExpression
  query?: AbilityQuery
  targets?: AbilityTargets
  options?: {
    text: string
    targets?: AbilityTargets
    effect: AbilityEffect
    requirements?: AbilityExpression[]
  }[]
  changes?: {
    attr: string
    mode: 'set' | 'add' | 'multiply'
    value: AbilityExpression
  }[]
  duration?: 'instant' | 'step' | 'phase' | 'turn' | 'round' | 'while_source_in_play' | 'permanent'
  handler?: string
  params?: {}
  [k: string]: unknown
}

export interface AbilityTrigger {
  event: string
  filter?: AbilityExpression
  window?: 'before' | 'after' | 'instead'
}

/**
 * The adversary's side of a cooperative game: which villain stages, schemes and encounter sets make up the opposition. The cooperative counterpart of a deck, and versioned, playtested and simulated the same way.
 */

export interface Scenario {
  $schema?: string
  schemaVersion: string
  gameId: string
  gameVersion: string
  id: string
  name: string
  /**
   * Which declared adversary side this scenario fills
   */
  adversary: string
  /**
   * Anchor id to card code. Stage progressions are given as an ordered array.
   */
  anchors?: {
    [k: string]: string | [string, ...string[]]
  }
  /**
   * Encounter set codes forming the encounter deck.
   */
  encounterSets?: string[]
  difficulty?: string
  setup?: AbilityEffect1
  playerCount?: {
    min?: number
    max?: number
  }
  design?: {
    /**
     * The difficulty the designer is aiming for, per player count. Simulation measures against this rather than against 50%.
     */
    targetWinRate?: {
      [k: string]: number
    }
    expectedRounds?: {
      min?: number
      max?: number
    }
    notes?: string
    tags?: string[]
  }
}

/**
 * An expansion / product containing cards. May carry a design budget used by the completeness view.
 */

export interface Set {
  $schema?: string
  schemaVersion: string
  code: string
  gameId: string
  name: string
  releaseOrder?: number
  status?: 'draft' | 'review' | 'released' | 'archived'
  summary?: string
  design?: {
    goals?: string[]
    /**
     * Planned card counts keyed by card type id
     */
    budget?: {
      [k: string]: number
    }
    notes?: string
  }
  /**
   * Inlined card documents (used by bundle export and the example game)
   */
  cards?: Card[]
}
