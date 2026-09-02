import { expect, test } from '@playwright/test'

/**
 * The end-to-end path a designer actually walks: browse the cards, edit one, deal a match,
 * play it.
 *
 * These run against a real API with Emberfall imported. That is the point — the three
 * layers each work on their own already, and the only question left is whether they agree.
 */

test('the card browser lists the imported set and filters it', async ({ page }) => {
  await page.goto('/g/emberfall/cards')

  const rows = page.locator('[data-card]')
  await expect(rows.first()).toBeVisible()
  const all = await rows.count()
  expect(all).toBeGreaterThan(10)

  // A facet is a server-side filter; the count coming down is the assertion that it is.
  await page.locator('.facet', { hasText: 'Character' }).first().locator('input').check()
  await expect.poll(async () => rows.count()).toBeLessThan(all)

  // The filter is in the URL, because a filtered view is something designers send to
  // each other.
  await expect(page).toHaveURL(/type/)
})

test('a card opens in an editor built from the compiled card type', async ({ page }) => {
  await page.goto('/g/emberfall/cards')
  await page.locator('[data-card="core-010"]').click()

  await expect(page).toHaveURL(/\/cards\/core-010/)

  // The form is generated from the compiled card type, so its fields are the ones
  // Emberfall's `character` declares — and only those.
  await expect(page.locator('.field')).toHaveCount(4)
  for (const field of ['cost', 'attack', 'health', 'traits']) {
    await expect(page.locator(`label[for="f-${field}"]`)).toBeVisible()
  }

  // A hero declares different attributes, and gets a different form with no code change.
  await page.goto('/g/emberfall/cards/core-001')
  await expect(page.locator('.field')).toHaveCount(2)
  await expect(page.locator('label[for="f-health"]')).toBeVisible()
  await expect(page.locator('label[for="f-cost"]')).toHaveCount(0)
})

test('an edit round-trips through the API and shows the saved revision', async ({ page }) => {
  await page.goto('/g/emberfall/cards/core-010')

  const name = page.locator('input.title')
  await expect(name).not.toHaveValue('')

  // Whatever the card is called now — the test restores it rather than assuming it, so a
  // run that fails part-way does not poison the next one.
  const original = await name.inputValue()
  const edited = `${original} ✎`

  try {
    await name.fill(edited)
    await page.getByRole('button', { name: 'Save' }).click()
    await expect(page.getByText(/saved .* · rev/)).toBeVisible()

    // Reloaded from the server, not from the store: this is the round trip.
    await page.reload()
    await expect(page.locator('input.title')).toHaveValue(edited)
  } finally {
    await page.locator('input.title').fill(original)
    await page.getByRole('button', { name: 'Save' }).click()
    await expect(page.getByText(/saved .* · rev/)).toBeVisible()
  }

  await page.reload()
  await expect(page.locator('input.title')).toHaveValue(original)
})

test('a rejected card shows the server’s violation against the field', async ({ page }) => {
  await page.goto('/g/emberfall/cards/core-010')
  await expect(page.locator('#f-cost')).toBeVisible()

  // 40 is past the maximum Emberfall's `character` type declares, so the server refuses it.
  // The client does not pre-empt that: the compiled schema the API validates against is the
  // only opinion that counts, and it is game data.
  await page.locator('#f-cost').fill('40')
  await page.getByRole('button', { name: 'Save' }).click()

  await expect(page.locator('.panel.error')).toBeVisible()
  await expect(page.locator('.pointer').first()).toHaveText('/attributes/cost')

  // Refused means not written: the reload shows the cost the card still has.
  await page.reload()
  await expect(page.locator('#f-cost')).not.toHaveValue('40')
})

test('a solo match deals, plays and records its actions', async ({ page }) => {
  await page.goto('/g/emberfall/play')

  await page.locator('select[data-seat="0"]').waitFor()

  // Seed 4 is pinned because Emberfall's setup ends with `set_first_player {"rule":
  // "random"}` — unseeded, this test would open on the bot's turn about half the time and
  // assert against a different game each run.
  await page.locator('input[type="number"]').fill('4')
  await expect(page.locator('[data-opponent]')).toHaveValue(/.+/)

  await page.getByRole('button', { name: /Start match/ }).click()
  await expect(page).toHaveURL(/\/m\/[0-9a-f-]{36}/)

  // The board is drawn from ui.board in the game document, and the action bar from the
  // server's legal action list.
  await expect(page.locator('[data-zone]').first()).toBeVisible()
  const actions = page.locator('[data-action]')
  await expect(actions.first()).toBeVisible()

  // On this seed the bot has the first turn, so the log already has its move in it before
  // the human has touched anything.
  const opening = page.locator('[data-log-entry]')
  await expect(opening.first()).toBeVisible()
  const openingEntries = await opening.count()

  const before = await page.locator('[data-state-hash]').getAttribute('data-state-hash')

  await actions.first().click()

  // A state hash that moved is the proof the server applied it; the client cannot fake one.
  await expect
    .poll(async () => page.locator('[data-state-hash]').getAttribute('data-state-hash'))
    .not.toBe(before)

  await expect.poll(async () => opening.count()).toBeGreaterThan(openingEntries)

  // And the board comes back to the human rather than stopping on the opponent's turn.
  await expect(actions.first()).toBeVisible()
})

test('the deck builder shows legality in the game’s own words', async ({ page }) => {
  await page.goto('/g/emberfall/decks')

  await expect(page.getByRole('heading', { name: 'Ash Control' })).toBeVisible()
  await expect(page.locator('.panel.ok')).toContainText('Legal')

  // The curve and the type split are the server's numbers, not the client's arithmetic.
  await expect(page.locator('.curve .col').first()).toBeVisible()
})
