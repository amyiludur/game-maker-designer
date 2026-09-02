import { Rand } from './random'

/**
 * Generates abilities in the shape `schemas/ability.schema.json` describes.
 *
 * These are structurally valid rather than semantically sensible — `deal_damage` to a
 * selector that could not resolve is fine here, because the properties under test are about
 * the editor not losing data, not about the ability meaning anything.
 */
const OPS = ['deal_damage', 'draw', 'gain_resource', 'destroy', 'add_modifier', 'move_card'] as const
const SELECTORS = ['$self', '$target', '$controller', '$opponent', '$event.source'] as const
const KINDS = ['triggered', 'activated', 'static', 'replacement'] as const
const SPEEDS = ['fast', 'slow'] as const

export function randomEffect(rand: Rand, depth = 0): Record<string, unknown> {
  // Past depth 2 only leaves are generated, so the trees stay small enough to read when
  // one of them fails.
  const shape = depth >= 2 ? 0 : rand.int(3)

  if (shape === 1) {
    return {
      op: 'sequence',
      effects: Array.from({ length: 1 + rand.int(2) }, () => randomEffect(rand, depth + 1)),
    }
  }

  if (shape === 2) {
    return {
      op: 'if',
      condition: { op: rand.pick(['gt', 'lt', 'eq'] as const), left: rand.pick(SELECTORS), right: rand.int(5) },
      then: randomEffect(rand, depth + 1),
      ...(rand.bool() ? { else: randomEffect(rand, depth + 1) } : {}),
    }
  }

  return {
    op: rand.pick(OPS),
    target: rand.pick(SELECTORS),
    amount: rand.int(6),
    ...(rand.bool() ? { optional: rand.bool() } : {}),
  }
}

export function randomAbility(rand: Rand): Record<string, unknown> {
  const kind = rand.pick(KINDS)

  return {
    id: `a${rand.int(1000)}`,
    kind,
    speed: rand.pick(SPEEDS),
    ...(kind === 'triggered' ? { trigger: { event: 'enters_play', filter: { op: 'is', left: '$event.card', right: '$self' } } } : {}),
    ...(kind === 'activated' ? { cost: { op: 'pay_resource', amount: rand.int(4) } } : {}),
    effect: randomEffect(rand),
    text: 'Generated for a property test.',
  }
}
