<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DeckController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\MatchController;
use Illuminate\Support\Facades\Route;

/*
 | The REST surface, per doc 10.
 |
 | Authoring is a document API: the client sends whole documents and gets violations back as
 | JSON Pointers. Playing is a command API: the client sends an action and gets back a
 | redacted view, the legal actions and the events that got there.
 */

Route::prefix('v1')->group(function (): void {
    Route::get('games', [GameController::class, 'index']);
    Route::get('games/{game}', [GameController::class, 'show']);
    Route::get('games/{game}/versions/{version}', [GameController::class, 'version']);
    Route::put('games/{game}/versions/{version}', [GameController::class, 'updateVersion']);
    Route::get('games/{game}/versions/{version}/compiled', [GameController::class, 'compiled']);
    Route::get('games/{game}/versions/{version}/lint', [GameController::class, 'lint']);

    Route::get('games/{game}/cards', [CardController::class, 'index']);
    Route::get('games/{game}/sets/{set}/completeness', [CardController::class, 'completeness']);
    Route::get('cards/{card}', [CardController::class, 'show']);
    Route::put('cards/{card}', [CardController::class, 'update']);

    Route::get('games/{game}/decks', [DeckController::class, 'index']);
    Route::post('games/{game}/decks/validate', [DeckController::class, 'validateDeck']);
    Route::get('decks/{deck}', [DeckController::class, 'show']);

    Route::post('matches', [MatchController::class, 'store']);
    Route::get('matches/{match}', [MatchController::class, 'show']);
    Route::post('matches/{match}/actions', [MatchController::class, 'act']);
    Route::post('matches/{match}/choice', [MatchController::class, 'choose']);
    Route::post('matches/{match}/undo', [MatchController::class, 'undo']);
    Route::get('matches/{match}/log', [MatchController::class, 'log']);
    Route::get('matches/{match}/replay', [MatchController::class, 'replay']);
});
