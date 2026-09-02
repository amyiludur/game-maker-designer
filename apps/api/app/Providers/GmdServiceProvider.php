<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Card;
use App\Models\CardSet;
use App\Models\Deck;
use App\Models\Game;
use App\Models\GameMatch;
use App\Models\GameVersion;
use App\Services\GameTemplates;
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

        $this->app->singleton(GameTemplates::class, static fn ($app): GameTemplates => new GameTemplates(
            (string) config('gmd.templates'),
        ));
    }

    public function boot(): void
    {
        // Games, versions, cards and sets are addressable by id or by their human name,
        // because a designer sharing a link types "emberfall", not a UUID. The id comparison
        // is skipped unless the value actually looks like one: Postgres rejects a
        // non-UUID string against a uuid column outright rather than simply not matching.
        Route::bind('game', static fn (string $value): Game => self::findBy(Game::query(), $value, 'slug'));
        Route::bind('card', static fn (string $value): Card => self::findBy(Card::query(), $value, 'code'));

        // A semver and a set code are unique inside a game, not across the platform: both
        // examples have a set called `core`, and an unscoped lookup answers
        // /games/wardens-hollow/sets/core with whichever one was imported first. When the
        // route names a game, it decides.
        Route::bind('version', static fn (string $value, $route): GameVersion => self::findBy(
            self::scopedTo(GameVersion::query(), $route),
            $value,
            'semver',
        ));
        Route::bind('set', static fn (string $value, $route): CardSet => self::findBy(
            self::scopedTo(CardSet::query(), $route),
            $value,
            'code',
        ));
        Route::bind('deck', static fn (string $value): Deck => Deck::query()->findOrFail($value));
        Route::bind('match', static fn (string $value): GameMatch => GameMatch::query()->findOrFail($value));
    }

    /**
     * Narrow a lookup to the game the route is already talking about, if it names one.
     *
     * Route parameters are substituted in the order they appear in the URI, so by the time a
     * `{set}` is resolved the `{game}` before it is already a model.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<T>  $query
     * @return \Illuminate\Database\Eloquent\Builder<T>
     */
    private static function scopedTo($query, ?\Illuminate\Routing\Route $route)
    {
        $game = $route?->parameter('game');

        return $game instanceof Game ? $query->where('game_id', $game->id) : $query;
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
