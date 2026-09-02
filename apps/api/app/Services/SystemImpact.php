<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Card;
use App\Models\Deck;
use App\Models\GameMatch;
use App\Models\GameVersion;
use App\Models\MatchAction;
use Gmd\Kernel\Diagnostics\CompileError;
use Gmd\Kernel\System\Lint;
use Gmd\Kernel\System\LintFinding;
use Gmd\Kernel\System\SystemDocument;

/**
 * What a proposed change to the system would break, before it is committed.
 *
 * "Removing this zone would invalidate 12 cards and 3 saved matches" is the one thing the
 * system editor has to be able to say (doc 12): a game document is small enough to edit
 * carelessly and load-bearing enough that a careless edit is expensive. Everything here is
 * evidence rather than opinion — the cards are named, the decks are named, and a designer
 * who wants to do it anyway can.
 *
 * The proposal is never written by this class. It compiles it, checks the world against it,
 * and throws it away.
 */
final class SystemImpact
{
    /** How many named examples a finding carries before it starts counting instead. */
    private const EVIDENCE = 12;

    /** Keys whose string values are prose, not references — a card named "Ember" is not a use of Ember. */
    private const PROSE = ['name', 'text', 'textOverride', 'flavor', 'summary', 'prompt', 'message', 'help', 'notes', 'title', 'body', 'label', 'artist'];

    /** The collections a system document is made of, and where each one lives in it. */
    private const COLLECTIONS = [
        'zones' => 'zones',
        'cardTypes' => 'cardTypes',
        'keywords' => 'keywords',
        'resources' => 'resources',
        'counters' => 'counters',
        'actions' => 'actions',
        'stateChecks' => 'stateChecks',
        'winConditions' => 'winConditions',
    ];

    public function __construct(
        private readonly GameCompiler $compiler,
        private readonly CardValidator $cards,
        private readonly DeckLegality $decks,
    ) {}

    /**
     * @param  array<string, mixed>  $proposed
     * @return array<string, mixed>
     */
    public function of(GameVersion $version, array $proposed): array
    {
        /** @var array<string, mixed> $current */
        $current = $version->document ?? [];

        $changes = $this->changes($current, $proposed);
        $findings = [];

        $before = null;
        try {
            $before = $this->compiler->compile($version);
        } catch (CompileError) {
            // The version on disk does not compile either. Everything below that needs a
            // "before" simply has nothing to compare against, which is not this change's
            // fault and is not worth a finding.
        }

        $after = null;
        $error = null;
        try {
            $after = $this->compiler->compile($this->as($version, $proposed));
        } catch (CompileError $e) {
            $error = $e->diagnostic()->jsonSerialize();
            $findings[] = [
                'severity' => 'error',
                'rule' => 'does-not-compile',
                'subject' => 'system',
                'message' => 'this system does not compile, so no match could be played on it',
                'count' => 1,
                'evidence' => [$e->getMessage()],
            ];
        }

        $findings = [...$findings, ...$this->danglingFindings($proposed, $changes)];

        if ($after !== null) {
            $findings = [
                ...$findings,
                ...$this->cardFindings($version, $changes, $before, $after),
                ...$this->deckFindings($version, $before, $after),
                ...$this->lintFindings($before, $after),
            ];
        }

        $findings = [...$findings, ...$this->historyFindings($version, $changes)];

        return [
            'compiles' => $after !== null,
            'error' => $error,
            'changes' => $changes,
            'findings' => $findings,
            'version' => $this->semver((string) $version->semver, $changes, $findings),
        ];
    }

    /**
     * What is gone, what is new, and what has been edited in place — per collection.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $proposed
     * @return array<string, array{removed: list<string>, added: list<string>, changed: list<string>}>
     */
    public function changes(array $current, array $proposed): array
    {
        $changes = [];

        foreach (self::COLLECTIONS as $key => $path) {
            $changes[$key] = $this->diff($this->byId($current[$path] ?? []), $this->byId($proposed[$path] ?? []));
        }

        $changes['phases'] = $this->diff(
            $this->byId($current['round']['phases'] ?? []),
            $this->byId($proposed['round']['phases'] ?? []),
        );
        $changes['steps'] = $this->diff($this->steps($current), $this->steps($proposed));
        $changes['traits'] = $this->diff(
            $this->flat($current['vocabularies']['traits'] ?? []),
            $this->flat($proposed['vocabularies']['traits'] ?? []),
        );
        $changes['factions'] = $this->diff(
            $this->byId($current['vocabularies']['factions'] ?? []),
            $this->byId($proposed['vocabularies']['factions'] ?? []),
        );

        return array_filter(
            $changes,
            static fn (array $change): bool => $change['removed'] !== [] || $change['added'] !== [] || $change['changed'] !== [],
        );
    }

    /**
     * The proposed system's own references to something it no longer declares.
     *
     * This is the consequence a designer cannot see from the tab they are standing in:
     * deleting Ember from the resources tab leaves three actions with a `pay_resource` cost
     * that can never be paid, and the round's income step gaining a resource that is not
     * there. Named by where they live — `actions.play_character`, `round.refresh.income` —
     * because that is where the fix has to happen.
     *
     * @param  array<string, mixed>  $proposed
     * @param  array<string, array{removed: list<string>, added: list<string>, changed: list<string>}>  $changes
     * @return list<array<string, mixed>>
     */
    private function danglingFindings(array $proposed, array $changes): array
    {
        $findings = [];

        foreach (['zones' => 'zone', 'resources' => 'resource', 'counters' => 'counter', 'cardTypes' => 'card type', 'keywords' => 'keyword', 'actions' => 'action', 'steps' => 'step'] as $collection => $noun) {
            foreach ($changes[$collection]['removed'] ?? [] as $id) {
                $sites = $this->sites($proposed, (string) $id);
                if ($sites === []) {
                    continue;
                }

                $findings[] = $this->finding(
                    'error',
                    'removed-still-referenced',
                    'system',
                    count($sites) . " place(s) in the system still name the {$noun} \"{$id}\"",
                    $sites,
                    'change those first, or rename it rather than removing it',
                );
            }
        }

        return $findings;
    }

    /**
     * Where in a system document a given id is still named.
     *
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function sites(array $document, string $id): array
    {
        $sites = [];

        foreach (['actions', 'stateChecks', 'winConditions'] as $collection) {
            foreach ($document[$collection] ?? [] as $entry) {
                if (is_array($entry) && $this->mentions($entry, $id)) {
                    $sites[] = $collection . '.' . (string) ($entry['id'] ?? '?');
                }
            }
        }

        foreach ($document['round']['phases'] ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if (is_array($step) && $this->mentions($step, $id)) {
                    $sites[] = 'round.' . (string) ($phase['id'] ?? '?') . '.' . (string) ($step['id'] ?? '?');
                }
            }
        }

        foreach (['setup', 'deckbuilding', 'cardTypes', 'keywords', 'adversaries', 'ui'] as $section) {
            if (isset($document[$section]) && $this->mentions($document[$section], $id)) {
                $sites[] = $section;
            }
        }

        return $sites;
    }

    /**
     * Cards: which ones stop being valid, and which ones name something that is going away.
     *
     * @param  array<string, array{removed: list<string>, added: list<string>, changed: list<string>}>  $changes
     * @return list<array<string, mixed>>
     */
    private function cardFindings(GameVersion $version, array $changes, ?SystemDocument $before, SystemDocument $after): array
    {
        $findings = [];
        $cards = Card::query()->where('game_id', $version->game_id)->orderBy('code')->get();

        $broken = [];
        foreach ($cards as $card) {
            $document = $card->document ?? [];
            if ($this->cards->violationsAgainst($after, $document) === []) {
                continue;
            }
            // Only cards this change breaks. A card that is already invalid is a fact about
            // today, and blaming it on the edit in front of you is how a warning gets
            // ignored.
            if ($before !== null && $this->cards->violationsAgainst($before, $document) !== []) {
                continue;
            }
            $broken[] = (string) $card->code;
        }

        if ($broken !== []) {
            $findings[] = $this->finding(
                'error',
                'cards-invalidated',
                'cards',
                count($broken) === 1
                    ? '1 card would stop being valid against its card type'
                    : count($broken) . ' cards would stop being valid against their card types',
                $broken,
                'fix the cards first, or keep the attribute and mark it optional',
            );
        }

        foreach (['keywords' => 'keyword', 'traits' => 'trait', 'factions' => 'faction'] as $collection => $noun) {
            foreach ($changes[$collection]['removed'] ?? [] as $id) {
                $carriers = $this->carriers($cards, $collection, (string) $id);
                if ($carriers === []) {
                    continue;
                }

                $findings[] = $this->finding(
                    'error',
                    "removed-{$noun}",
                    'cards',
                    count($carriers) . ' card(s) carry the ' . $noun . " \"{$id}\"",
                    $carriers,
                    "clear it from those cards first, or rename the {$noun} instead of removing it",
                );
            }
        }

        // Everything else a card can name: a zone it moves cards to, a resource its ability
        // pays, a counter it puts on something.
        foreach (['zones' => 'zone', 'resources' => 'resource', 'counters' => 'counter', 'cardTypes' => 'card type'] as $collection => $noun) {
            foreach ($changes[$collection]['removed'] ?? [] as $id) {
                $mentions = [];
                foreach ($cards as $card) {
                    if ($this->mentions($card->document ?? [], (string) $id)) {
                        $mentions[] = (string) $card->code;
                    }
                }
                if ($mentions === []) {
                    continue;
                }

                $findings[] = $this->finding(
                    'warning',
                    'removed-reference',
                    'cards',
                    count($mentions) . " card(s) name the {$noun} \"{$id}\" somewhere in their abilities",
                    $mentions,
                );
            }
        }

        return $findings;
    }

    /**
     * Decks: which ones were legal and would stop being.
     *
     * @return list<array<string, mixed>>
     */
    private function deckFindings(GameVersion $version, ?SystemDocument $before, SystemDocument $after): array
    {
        $broken = [];

        foreach (Deck::query()->where('game_id', $version->game_id)->with('head')->get() as $deck) {
            $document = $deck->head?->document;
            if (! is_array($document)) {
                continue;
            }
            if ($before !== null && ($this->decks->checkAgainst($before, $document)['valid'] ?? false) !== true) {
                continue;
            }
            if (($this->decks->checkAgainst($after, $document)['valid'] ?? false) === true) {
                continue;
            }

            $broken[] = (string) $deck->name;
        }

        if ($broken === []) {
            return [];
        }

        return [$this->finding(
            'error',
            'decks-invalidated',
            'decks',
            count($broken) . ' deck(s) that are legal today would stop being legal',
            $broken,
        )];
    }

    /**
     * Lint findings this change introduces — not the ones it inherits.
     *
     * @return list<array<string, mixed>>
     */
    private function lintFindings(?SystemDocument $before, SystemDocument $after): array
    {
        $lint = Lint::standard();
        $existing = [];
        foreach ($before === null ? [] : $lint->check($before) as $finding) {
            $existing[$finding->rule . '|' . $finding->message] = true;
        }

        $introduced = [];
        foreach ($lint->check($after) as $finding) {
            if (! isset($existing[$finding->rule . '|' . $finding->message])) {
                $introduced[$finding->severity][] = $finding;
            }
        }

        $findings = [];
        foreach ([LintFinding::ERROR => 'error', LintFinding::WARNING => 'warning'] as $severity => $label) {
            $group = $introduced[$severity] ?? [];
            if ($group === []) {
                continue;
            }

            $findings[] = $this->finding(
                $label,
                'lint-introduced',
                'system',
                count($group) . " new lint {$label}(s)",
                array_map(static fn (LintFinding $f): string => $f->message, $group),
            );
        }

        return $findings;
    }

    /**
     * Matches and replays recorded under the rules that are about to change.
     *
     * A replay is a list of actions plus a seed; it reproduces because the rules that read
     * them are the same rules. Change the rules and it is no longer the same experiment.
     *
     * @param  array<string, array{removed: list<string>, added: list<string>, changed: list<string>}>  $changes
     * @return list<array<string, mixed>>
     */
    private function historyFindings(GameVersion $version, array $changes): array
    {
        $findings = [];

        foreach ($changes['actions']['removed'] ?? [] as $id) {
            $matches = MatchAction::query()
                ->whereIn('match_id', GameMatch::query()->where('game_version_id', $version->id)->select('id'))
                ->where('action->actionId', $id)
                ->distinct()
                ->pluck('match_id');

            if ($matches->isEmpty()) {
                continue;
            }

            $findings[] = $this->finding(
                'warning',
                'removed-action-in-history',
                'matches',
                $matches->count() . " recorded match(es) took the action \"{$id}\", which would no longer exist",
                $matches->map(static fn (mixed $id): string => (string) $id)->all(),
            );
        }

        $breaking = $this->removals($changes);
        $recorded = GameMatch::query()->where('game_version_id', $version->id)->count();
        if ($breaking !== [] && $recorded > 0) {
            $findings[] = $this->finding(
                'info',
                'history-may-not-reproduce',
                'matches',
                "{$recorded} match(es) were recorded under these rules and may no longer replay identically",
                [],
                'export any replay you still need as a conformance fixture before saving',
            );
        }

        return $findings;
    }

    /**
     * What the version number should become.
     *
     * Semver's own rule, read for a project below 1.0: while the major is 0 a breaking change
     * moves the minor, which is what "0.4.0 → 0.5.0 (major)" means on the impact panel.
     *
     * @param  array<string, array{removed: list<string>, added: list<string>, changed: list<string>}>  $changes
     * @param  list<array<string, mixed>>  $findings
     * @return array{from: string, suggested: string, classification: string}
     */
    private function semver(string $from, array $changes, array $findings): array
    {
        $breaks = $this->removals($changes) !== []
            || array_filter($findings, static fn (array $f): bool => $f['severity'] === 'error') !== [];

        $adds = false;
        $edits = false;
        foreach ($changes as $change) {
            $adds = $adds || $change['added'] !== [];
            $edits = $edits || $change['changed'] !== [];
        }

        $classification = match (true) {
            $breaks => 'major',
            $adds => 'minor',
            $edits => 'patch',
            default => 'none',
        };

        return [
            'from' => $from,
            'suggested' => $this->bump($from, $classification),
            'classification' => $classification,
        ];
    }

    private function bump(string $version, string $classification): string
    {
        [$major, $minor, $patch] = array_pad(array_map('intval', explode('.', $version)), 3, 0);

        // Below 1.0, semver's own convention is that the minor carries the breaking changes
        // and everything else is a patch — which is why the panel says 0.4.0 → 0.5.0 and
        // calls it major.
        if ($major === 0) {
            return match ($classification) {
                'major' => '0.' . ($minor + 1) . '.0',
                'minor', 'patch' => "0.{$minor}." . ($patch + 1),
                default => $version,
            };
        }

        return match ($classification) {
            'major' => ($major + 1) . '.0.0',
            'minor' => $major . '.' . ($minor + 1) . '.0',
            'patch' => $major . '.' . $minor . '.' . ($patch + 1),
            default => $version,
        };
    }

    /**
     * @param  array<string, array{removed: list<string>, added: list<string>, changed: list<string>}>  $changes
     * @return list<string>
     */
    private function removals(array $changes): array
    {
        $removed = [];
        foreach ($changes as $collection => $change) {
            foreach ($change['removed'] as $id) {
                $removed[] = "{$collection}.{$id}";
            }
        }

        return $removed;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Card>  $cards
     * @return list<string>
     */
    private function carriers($cards, string $collection, string $id): array
    {
        $codes = [];

        foreach ($cards as $card) {
            $carries = match ($collection) {
                // The index columns, which is exactly what they are for: this is a question
                // about cards, not a question about a game in progress (ADR-0001).
                'keywords' => in_array($id, (array) $card->keywords, true),
                'traits' => in_array($id, (array) $card->traits, true),
                default => $card->faction === $id,
            };

            if ($carries) {
                $codes[] = (string) $card->code;
            }
        }

        return $codes;
    }

    /** Does this document reference `$needle` anywhere that is not prose? */
    private function mentions(mixed $node, string $needle, ?string $key = null): bool
    {
        if (is_string($node)) {
            return $node === $needle && ! in_array($key, self::PROSE, true);
        }
        if (! is_array($node)) {
            return false;
        }

        foreach ($node as $childKey => $child) {
            if ($this->mentions($child, $needle, is_string($childKey) ? $childKey : $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $evidence
     * @return array<string, mixed>
     */
    private function finding(string $severity, string $rule, string $subject, string $message, array $evidence, ?string $fix = null): array
    {
        return array_filter([
            'severity' => $severity,
            'rule' => $rule,
            'subject' => $subject,
            'message' => $message,
            'count' => count($evidence),
            'evidence' => array_slice($evidence, 0, self::EVIDENCE),
            'fix' => $fix,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{removed: list<string>, added: list<string>, changed: list<string>}
     */
    private function diff(array $before, array $after): array
    {
        $removed = array_values(array_diff(array_keys($before), array_keys($after)));
        $added = array_values(array_diff(array_keys($after), array_keys($before)));

        $changed = [];
        foreach ($before as $id => $entry) {
            if (array_key_exists($id, $after) && $after[$id] !== $entry) {
                $changed[] = (string) $id;
            }
        }

        return ['removed' => $removed, 'added' => $added, 'changed' => $changed];
    }

    /**
     * @param  mixed  $entries
     * @return array<string, mixed>
     */
    private function byId($entries): array
    {
        $indexed = [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && isset($entry['id']) && is_string($entry['id'])) {
                $indexed[$entry['id']] = $entry;
            }
        }

        return $indexed;
    }

    /**
     * @param  mixed  $values
     * @return array<string, mixed>
     */
    private function flat($values): array
    {
        $indexed = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value)) {
                $indexed[$value] = $value;
            }
        }

        return $indexed;
    }

    /**
     * Steps, keyed the way the rest of the system refers to them: `combat.resolve`.
     *
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function steps(array $document): array
    {
        $steps = [];
        foreach ($document['round']['phases'] ?? [] as $phase) {
            foreach ($phase['steps'] ?? [] as $step) {
                if (isset($phase['id'], $step['id'])) {
                    $steps[$phase['id'] . '.' . $step['id']] = $step;
                }
            }
        }

        return $steps;
    }

    /**
     * The proposal as a version object the compiler will read, saved to nothing.
     *
     * @param  array<string, mixed>  $document
     */
    private function as(GameVersion $version, array $document): GameVersion
    {
        $proposal = $version->replicate();
        $proposal->document = $document;
        // The compiler reads the game's sets off the version's game, so the id has to travel
        // even though this object is never saved.
        $proposal->game_id = $version->game_id;

        return $proposal;
    }
}
