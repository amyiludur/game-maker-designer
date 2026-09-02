<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Card;
use App\Models\CardSet;
use App\Models\Deck;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameVersion;
use App\Services\SchemaValidator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

final class GmdServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SchemaValidator::class, static fn ($app): SchemaValidator => new SchemaValidator(
            (string) config('gmd.schemas'),
        ));
    }

    public function boot(): void
    {
        // Games, versions, cards and sets are addressable by id or by their human name,
        // because a designer sharing a link types "emberfall", not a UUID. The id comparison
        // is skipped unless the value actually looks like one: Postgres rejects a
        // non-UUID string against a uuid column outright rather than simply not matching.
        Route::bind('game', static fn (string $value): Game => self::findBy(Game::query(), $value, 'slug'));
        Route::bind('version', static fn (string $value): GameVersion => self::findBy(GameVersion::query(), $value, 'semver'));
        Route::bind('card', static fn (string $value): Card => self::findBy(Card::query(), $value, 'code'));
        Route::bind('set', static fn (string $value): CardSet => self::findBy(CardSet::query(), $value, 'code'));
        Route::bind('deck', static fn (string $value): Deck => Deck::query()->findOrFail($value));
        Route::bind('match', static fn (string $value): GameMatch => GameMatch::query()->findOrFail($value));
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<T>  $query
     * @return T
     */
    private static function findBy($query, string $value, string $column)
    {
        if (Str::isUuid($value)) {
            return $query->findOrFail($value);
        }

        return $query->where($column, $value)->firstOrFail();
    }
}
