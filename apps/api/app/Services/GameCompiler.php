<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Card;
use App\Models\GameVersion;
use Gmd\Kernel\Diagnostics\CompileError;
use Gmd\Kernel\System\Lint;
use Gmd\Kernel\System\LintFinding;
use Gmd\Kernel\System\SystemCompiler;
use Gmd\Kernel\System\SystemDocument;

/**
 * Compiles a stored game version into what the kernel plays and what the editor renders.
 *
 * The kernel does no I/O, so this is the seam: documents come out of jsonb columns here and
 * go in as plain arrays. The same seam lets `gmd` read them from files, which is why the
 * kernel could be proven before this existed.
 *
 * `compiled` is cached on the version but is never the truth — it is deterministic from the
 * document and can be thrown away at any time.
 */
final class GameCompiler
{
    public function __construct(private readonly SystemCompiler $compiler = new SystemCompiler) {}

    public function compile(GameVersion $version): SystemDocument
    {
        return $this->compiler->compile($version->document ?? [], $this->sets($version));
    }

    /**
     * Compile, lint, and cache both onto the version.
     *
     * @return array{system: ?SystemDocument, lint: array<string, mixed>}
     */
    public function refresh(GameVersion $version): array
    {
        try {
            $system = $this->compile($version);
        } catch (CompileError $e) {
            // A system that will not compile still has to be saveable — a designer mid-edit
            // is often in a broken state, and refusing the write would make the editor
            // unusable. The failure is recorded where the UI can show it.
            $version->compiled = null;
            $version->lint = ['compiled' => false, 'findings' => [$e->diagnostic()->jsonSerialize()]];
            $version->save();

            return ['system' => null, 'lint' => $version->lint];
        }

        $findings = Lint::standard()->check($system);

        $version->compiled = $this->descriptors($system);
        $version->lint = [
            'compiled' => true,
            'errors' => count(array_filter($findings, fn (LintFinding $f): bool => $f->severity === LintFinding::ERROR)),
            'findings' => array_map(static fn (LintFinding $f): array => $f->jsonSerialize(), $findings),
        ];
        $version->save();

        return ['system' => $system, 'lint' => $version->lint];
    }

    /**
     * What the card editor builds its forms from.
     *
     * A form descriptor per card type, so a game with completely different attributes needs
     * no frontend change — the editor renders what the game says its cards have.
     *
     * @return array<string, mixed>
     */
    public function descriptors(SystemDocument $system): array
    {
        $cardTypes = [];
        foreach ($system->cardTypes as $type) {
            $fields = [];
            foreach ($type->attributes as $attribute) {
                $fields[] = array_filter([
                    'id' => $attribute->id,
                    'name' => $attribute->name,
                    'type' => $attribute->type,
                    'required' => $attribute->required,
                    // Published so that a client building a blank card of this type reads the
                    // same declared default the server does.
                    'default' => $attribute->default,
                    'min' => $attribute->min,
                    'max' => $attribute->max,
                    'options' => $attribute->options,
                    'vocabulary' => $attribute->vocabulary,
                    'perPlayer' => $attribute->perPlayer ?: null,
                    'showOnCard' => $attribute->showOnCard,
                    'help' => $attribute->help,
                    // The constraint belongs next to the field, which is where the card
                    // editor prints it: "int 0-10" rather than a validation error later.
                    'constraint' => $this->constraint($attribute->type, $attribute->min, $attribute->max),
                ], static fn (mixed $v): bool => $v !== null && $v !== false);
            }

            $cardTypes[$type->id] = [
                'id' => $type->id,
                'name' => $type->name,
                'fields' => $fields,
                'modifiableAttributes' => $type->modifiableAttributes,
                'playableTo' => $type->playableTo,
                'doubleSided' => $type->doubleSided,
                'isIdentity' => $type->isIdentity,
                'schema' => $this->schemaFor($type),
            ];
        }

        return [
            'digest' => $system->digest,
            // How many seats a table may have, and whether any of them is a script. The
            // setup screen reads the shape of a game from these rather than being told: a
            // game that declares an adversary is played against a scenario, and one that
            // does not is played against another chair.
            'players' => [
                'min' => $system->minPlayers(),
                'max' => $system->maxPlayers(),
                'mode' => $system->players['mode'] ?? 'competitive',
            ],
            'adversaries' => array_map(
                static fn ($a): array => ['id' => $a->id, 'name' => $a->name, 'zones' => $a->zones],
                $system->adversaries,
            ),
            'cardTypes' => $cardTypes,
            'vocabularies' => $system->vocabularies,
            'keywords' => array_map(
                static fn ($k): array => ['id' => $k->id, 'name' => $k->name, 'reminder' => $k->reminder, 'parameters' => $k->parameters],
                $system->keywords,
            ),
            'zones' => array_map(
                static fn ($z): array => ['id' => $z->id, 'name' => $z->name, 'scope' => $z->scope, 'visibility' => $z->visibility],
                $system->zones,
            ),
            'phases' => array_map(
                static fn ($p): array => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'steps' => array_map(
                        static fn ($s): array => ['id' => $s->id, 'name' => $s->name, 'kind' => $s->hasAuto ? 'auto' : 'window'],
                        $p->steps,
                    ),
                ],
                $system->phases,
            ),
            'ui' => $system->ui,
        ];
    }

    /** A JSON Schema for one card type's attributes, used by both sides of the editor. */
    private function schemaFor(\Gmd\Kernel\System\CardTypeDefinition $type): array
    {
        $properties = [];
        $required = [];

        foreach ($type->attributes as $attribute) {
            $property = match ($attribute->type) {
                'integer' => array_filter([
                    'type' => 'integer',
                    'minimum' => $attribute->min,
                    'maximum' => $attribute->max,
                ], static fn (mixed $v): bool => $v !== null),
                'decimal' => ['type' => 'number'],
                'boolean' => ['type' => 'boolean'],
                'enum' => ['enum' => $attribute->options ?? []],
                'tagList' => ['type' => 'array', 'items' => ['type' => 'string']],
                default => ['type' => 'string'],
            };

            $properties[$attribute->id] = $property;
            if ($attribute->required) {
                $required[] = $attribute->id;
            }
        }

        return array_filter([
            'type' => 'object',
            // `\stdClass` when empty, not `[]`: a card type with no attributes — a treachery,
            // a scheme — otherwise compiles to `"properties": []`, which is not valid JSON
            // Schema, and every validator handed this bundle rejects the schema rather than
            // the card.
            'properties' => $properties === [] ? new \stdClass : $properties,
            'required' => $required === [] ? null : $required,
            'additionalProperties' => false,
        ], static fn (mixed $v): bool => $v !== null);
    }

    private function constraint(string $type, int|float|null $min, int|float|null $max): ?string
    {
        if ($type !== 'integer' && $type !== 'decimal') {
            return null;
        }
        $label = $type === 'integer' ? 'int' : 'num';

        return match (true) {
            $min !== null && $max !== null => "{$label} {$min}–{$max}",
            $min !== null => "{$label} ≥ {$min}",
            $max !== null => "{$label} ≤ {$max}",
            default => $label,
        };
    }

    /** @return list<array<string, mixed>> the set documents, each with its cards inlined */
    private function sets(GameVersion $version): array
    {
        $cards = Card::query()
            ->where('game_id', $version->game_id)
            ->orderBy('code')
            ->get()
            ->groupBy('set_id');

        $sets = [];
        foreach (\App\Models\CardSet::query()->where('game_id', $version->game_id)->orderBy('release_order')->get() as $set) {
            $sets[] = [
                ...$set->document,
                'code' => $set->code,
                'cards' => $cards->get($set->id, collect())->map(fn (Card $c): array => $c->document)->values()->all(),
            ];
        }

        return $sets;
    }
}
